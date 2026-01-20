<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use Illuminate\Http\Request;
use App\Exports\TaxCasesExport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class TaxCaseExportController
{
    /**
     * Export filtered tax cases to Excel
     * Groups by entity, creates multiple sheets
     */
    public function export(Request $request)
    {
        try {
            // Get authenticated user
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            Log::info('Export request params:', $request->all());

            // Build query with all relationships needed for export
            $query = TaxCase::query()
                ->with([
                    'entity',
                    'fiscalYear',
                    'currency',
                    'period',
                    'workflowHistories' => function ($q) {
                        $q->orderBy('stage_id')->orderBy('created_at', 'desc');
                    },
                    'sp2Record',
                    'sphpRecord',
                    'skpRecord',
                    'objectionSubmission',
                    'spuhRecord',
                    'objectionDecision',
                    'appealSubmission',
                    'appealExplanationRequest',
                    'appealDecision',
                    'supremeCourtSubmission',
                    'supremeCourtDecision',
                    'kianSubmission'
                ]);

            // Apply filters from request
            if ($request->filled('case_type')) {
                $query->where('case_type', $request->case_type);
            }

            if ($request->filled('entity_id')) {
                $query->where('entity_id', $request->entity_id);
            }

            if ($request->filled('case_status')) {
                $isCompleted = $request->case_status === 'closed' ? 1 : 0;
                $query->where('is_completed', $isCompleted);
            }

            if ($request->filled('current_stage')) {
                $query->where('current_stage', $request->current_stage);
            }

            if ($request->filled('period_id')) {
                $query->where('period_id', $request->period_id);
            }

            // Search by case number (wrapped in group for proper OR logic)
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('case_number', 'like', "%{$search}%")
                        ->orWhereHas('entity', function ($subQuery) use ($search) {
                            $subQuery->where('name', 'like', "%{$search}%");
                        });
                });
            }

            // Get filtered cases
            $taxCases = $query->get();

            Log::info('Export found tax cases count:', ['count' => $taxCases->count(), 'user_id' => $user->id]);
            Log::info('Tax cases data debug:', $taxCases->map(function($tc) {
                return [
                    'id' => $tc->id,
                    'case_number' => $tc->case_number,
                    'current_stage' => $tc->current_stage,
                    'disputed_amount' => $tc->disputed_amount,
                    'currency_id' => $tc->currency_id,
                    'currency_code' => $tc->currency?->code,
                    'currency_exchange_rate' => $tc->currency?->exchange_rate,
                    'entity_id' => $tc->entity_id,
                    'entity_name' => $tc->entity?->name,
                ];
            })->toArray());
            Log::info('Export entity distribution:', [
                'entities' => $taxCases->groupBy('entity_id')->map(function($cases, $entityId) {
                    $entityCode = $cases->first()->entity?->code ?? "Unknown";
                    $entityName = $cases->first()->entity?->name ?? "Unknown";
                    return [
                        'entity_id' => $entityId,
                        'entity_code' => $entityCode,
                        'entity_name' => $entityName,
                        'cases_count' => $cases->count()
                    ];
                })->values()
            ]);

            if ($taxCases->isEmpty()) {
                Log::warning('No tax cases found for export', [
                    'user_id' => $user->id,
                    'filters' => $request->all(),
                    'total_cases_in_db' => TaxCase::count()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'No tax cases found with current filters. Try removing some filters.'
                ], 404);
            }

            // Generate filename with timestamp
            $filename = 'tax-cases-export-' . now()->format('Y-m-d-His') . '.xlsx';

            // Create and download excel file
            return Excel::download(new TaxCasesExport($taxCases), $filename);

        } catch (\Exception $e) {
            Log::error('Tax case export error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export tax cases: ' . $e->getMessage()
            ], 500);
        }
    }
}
