<?php

namespace App\Exports;

use App\Models\TaxCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class TaxCasesExport implements WithMultipleSheets
{
    protected $taxCases;

    public function __construct(Collection $taxCases)
    {
        $this->taxCases = $taxCases;
    }

    public function sheets(): array
    {
        $sheets = [];

        // Group by entity
        $groupedByEntity = $this->taxCases->groupBy('entity_id');

        Log::info('Export grouping:', [
            'total_cases' => $this->taxCases->count(),
            'entities_count' => $groupedByEntity->count(),
            'entities' => $groupedByEntity->map(function($cases, $entityId) {
                return [
                    'entity_id' => $entityId,
                    'entity_code' => $cases->first()->entity?->code ?? "Unknown",
                    'entity_name' => $cases->first()->entity?->name ?? "Unknown",
                    'cases_count' => $cases->count()
                ];
            })->values()
        ]);

        $sheetIndex = 0;
        foreach ($groupedByEntity as $entityId => $cases) {
            // Get entity code from first case (use code instead of name for sheet name)
            $entityCode = $cases->first()->entity?->code ?? "Entity_{$entityId}";
            $entityName = $cases->first()->entity?->name ?? "Unknown";
            
            // Sanitize sheet name (Excel max 31 chars, no special chars)
            $sheetName = substr(preg_replace('/[^a-zA-Z0-9\s_-]/', '', $entityCode), 0, 31);
            
            Log::info("Creating sheet [{$sheetIndex}]: {$sheetName} (Entity: {$entityName}) for entity_id: {$entityId} with {$cases->count()} cases");
            
            // Use numeric index for sheets, not string key
            $sheets[$sheetIndex] = (new TaxCaseSheet($cases))->setTitle($sheetName);
            $sheetIndex++;
        }

        return $sheets;
    }
}

class TaxCaseSheet implements FromCollection, WithHeadings, ShouldAutoSize, WithTitle
{
    protected $cases;
    protected $sheetTitle = 'Sheet';

    // Stage name mapping
    protected $stageNames = [
        1 => 'SPT Filing',
        2 => 'SP2',
        3 => 'SPHP',
        4 => 'SKP',
        5 => 'Objection Submission',
        6 => 'SPUH',
        7 => 'Objection Decision',
        8 => 'Appeal Submission',
        9 => 'Appeal Explanation',
        10 => 'Appeal Decision',
        11 => 'Supreme Court Submission',
        12 => 'Supreme Court Decision',
        13 => 'Bank Transfer Request',
        14 => 'Transfer Instruction',
        15 => 'Refund Received',
        16 => 'KIAN Report'
    ];

    public function __construct(Collection $cases)
    {
        $this->cases = $cases;
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function setTitle(string $title): self
    {
        $this->sheetTitle = $title;
        return $this;
    }

    public function headings(): array
    {
        return [
            'FY',
            'Ref #',
            'Input Date',
            'Audit Status',
            'Kian',
            'Amount (Orig. Curr)',
            'Amount (USD)',
            'Tax Appeal #',
            'Tax Appeal',
            'Next Action',
            'Next Action Due Date',
            'Status Comments',
        ];
    }

    public function collection()
    {
        $rows = [];

        foreach ($this->cases as $taxCase) {
            // Get fiscal year
            $fy = $taxCase->fiscalYear?->year ?? '';
            $refNumber = $taxCase->case_number;
            $inputDate = $taxCase->created_at?->format('d/m/Y');
            $nextAction = $taxCase->next_action;
            $nextActionDueDate = $taxCase->next_action_due_date?->format('d-M-y');
            $statusComments = $taxCase->status_comment;
            $kianStatus = $taxCase->kianSubmission?->status ?? '';
            $currentStage = $taxCase->current_stage;

            // Get amount based on current stage
            $amountOriginal = $this->getAmountOriginal($taxCase, $currentStage);
            $amountUsd = $this->convertToUsd($taxCase, $amountOriginal);

            // Debug logging
            Log::info("Exporting tax case: {$refNumber}", [
                'workflow_histories_count' => $taxCase->workflowHistories?->count() ?? 0,
                'current_stage' => $currentStage,
                'amount_original' => $amountOriginal,
                'amount_usd' => $amountUsd,
                'currency_code' => $taxCase->currency?->code,
                'currency_exchange_rate' => $taxCase->currency?->exchange_rate,
            ]);

            // If no workflow histories, create a default row with basic info
            if (!$taxCase->workflowHistories || $taxCase->workflowHistories->count() === 0) {
                $rows[] = [
                    'FY' => $fy,
                    'Ref #' => $refNumber,
                    'Input Date' => $inputDate,
                    'Audit Status' => '',
                    'Kian' => $kianStatus,
                    'Amount (Orig. Curr)' => $amountOriginal ? (float)$amountOriginal : '',
                    'Amount (USD)' => $amountUsd ? (float)$amountUsd : '',
                    'Tax Appeal #' => '',
                    'Tax Appeal' => '',
                    'Next Action' => $nextAction,
                    'Next Action Due Date' => $nextActionDueDate,
                    'Status Comments' => $statusComments,
                ];
            } else {
                // Group workflow histories by stage_id and get the latest one per stage
                $historisByStage = $taxCase->workflowHistories
                    ->groupBy('stage_id')
                    ->map(function($stageHistories) {
                        // Get the latest (most recent) history for this stage
                        return $stageHistories->sortByDesc('created_at')->first();
                    })
                    ->sortBy('stage_id')
                    ->values();

                // Create a row for each unique stage
                foreach ($historisByStage as $history) {
                    $stageId = $history->stage_id;
                    $stageName = $this->stageNames[$stageId] ?? "Stage {$stageId}";

                    // Determine if this is Audit Status (stage 1-7) or Tax Appeal (stage 8-10)
                    $auditStatus = '';
                    $taxAppealNumber = '';
                    $taxAppealName = '';

                    if ($stageId >= 1 && $stageId <= 7) {
                        // Audit Status - include stage name in the status
                        $auditStatus = $stageName;
                    } elseif ($stageId >= 8 && $stageId <= 10) {
                        // Tax Appeal stages
                        // Get appeal number from related appeal records
                        if ($stageId === 8 && $taxCase->appealSubmission) {
                            $taxAppealNumber = $taxCase->appealSubmission->appeal_reference_number ?? '';
                        } elseif ($stageId === 9 && $taxCase->appealExplanationRequest) {
                            $taxAppealNumber = $taxCase->appealExplanationRequest->request_reference_number ?? '';
                        } elseif ($stageId === 10 && $taxCase->appealDecision) {
                            $taxAppealNumber = $taxCase->appealDecision->decision_reference_number ?? '';
                        }
                        
                        // Create numbered appeal name
                        $appealIndex = $stageId - 7; // 8->1, 9->2, 10->3
                        $taxAppealName = "{$appealIndex}. {$stageName}";
                    }

                    $rows[] = [
                        'FY' => $fy,
                        'Ref #' => $refNumber,
                        'Input Date' => $inputDate,
                        'Audit Status' => $auditStatus,
                        'Kian' => $kianStatus,
                        'Amount (Orig. Curr)' => $amountOriginal ? (float)$amountOriginal : '',
                        'Amount (USD)' => $amountUsd ? (float)$amountUsd : '',
                        'Tax Appeal #' => $taxAppealNumber,
                        'Tax Appeal' => $taxAppealName,
                        'Next Action' => $nextAction,
                        'Next Action Due Date' => $nextActionDueDate,
                        'Status Comments' => $statusComments,
                    ];
                }
            }
        }

        return collect($rows);
    }

    /**
     * Get amount original based on current stage
     * Stage 1-3: disputed_amount from tax_cases
     * Stage 4-6: skp_amount from skp_records
     * Stage 7-9: objection_amount from objection_submissions
     * Stage 10-12: decision_amount from objection_decisions
     */
    private function getAmountOriginal($taxCase, $currentStage)
    {
        if ($currentStage >= 1 && $currentStage <= 3) {
            return $taxCase->disputed_amount;
        } elseif ($currentStage >= 4 && $currentStage <= 6) {
            return $taxCase->skpRecord?->skp_amount;
        } elseif ($currentStage >= 7 && $currentStage <= 9) {
            return $taxCase->objectionSubmission?->objection_amount;
        } elseif ($currentStage >= 10 && $currentStage <= 12) {
            return $taxCase->objectionDecision?->decision_amount;
        }
        
        return $taxCase->disputed_amount; // Default fallback
    }

    /**
     * Convert original amount to USD using exchange rate
     * USD Amount = Original Amount / Currency Exchange Rate
     * Example: 1,000,000 IDR / 15000 (exchange rate) = 66.67 USD
     */
    private function convertToUsd($taxCase, $amountOriginal)
    {
        if (!$amountOriginal) {
            return null;
        }

        // If currency is not set
        if (!$taxCase->currency) {
            return $amountOriginal;
        }

        // If currency is already USD
        if ($taxCase->currency->code === 'USD') {
            return $amountOriginal;
        }

        // Divide by exchange rate
        $exchangeRate = $taxCase->currency->exchange_rate ?? 1;
        
        if ($exchangeRate == 0) {
            return null;
        }

        return $amountOriginal / $exchangeRate;
    }
}
