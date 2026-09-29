<?php

namespace App\Services;

use App\Models\TaxCase;
use App\Services\TaxWorkflowMatrix\StageRoutingResolver;

class MainWorkflowTransitionGuard
{
    private const FINAL_STATUSES = ['submitted', 'approved', 'completed'];

    public function __construct(private StageRoutingResolver $routingResolver) {}

    public function inspect(TaxCase $taxCase, int $stage): array
    {
        if ($stage < 1 || $stage > 12) {
            return $this->denied(422, 'Main workflow stages must be between 1 and 12.');
        }

        $this->loadEvidence($taxCase, $stage);

        if ($taxCase->is_completed || strtoupper((string) $taxCase->status?->code) === 'CLOSED') {
            return $this->denied(409, 'Completed or closed tax cases cannot accept workflow changes.');
        }

        $submitted = $this->submittedStages($taxCase);
        if (isset($submitted[$stage])) {
            return $this->denied(409, "Stage {$stage} has already been submitted and cannot be rewritten through the normal workflow endpoint.");
        }

        if ($stage === 1) {
            return $this->allowed();
        }

        $predecessor = $stage - 1;
        if (! isset($submitted[$predecessor])) {
            return $this->denied(409, "Stage {$predecessor} must be submitted before Stage {$stage}.");
        }

        if ($stage === 5 && ! $this->continuesAfterStage4($taxCase)) {
            return $this->denied(409, 'Stage 5 requires Stage 4 to be submitted with Continue To Next Stage selected.');
        }

        if ($stage === 8 && $this->routingResolver->resolveAfterStage7($taxCase)['state'] !== 'included') {
            return $this->denied(409, 'Stage 8 is not included by the persisted Stage 7 decision.');
        }

        if ($stage === 11 && $this->routingResolver->resolveAfterStage10($taxCase)['state'] !== 'included') {
            return $this->denied(409, 'Stage 11 is not included by the persisted Stage 10 decision.');
        }

        return $this->allowed();
    }

    public function accessibleStages(TaxCase $taxCase): array
    {
        $this->loadEvidence($taxCase);
        $submitted = $this->submittedStages($taxCase);
        $accessible = array_map('intval', array_keys($submitted));

        if ($taxCase->is_completed || strtoupper((string) $taxCase->status?->code) === 'CLOSED') {
            sort($accessible);

            return $accessible;
        }

        for ($stage = 1; $stage <= 12; $stage++) {
            if (! isset($submitted[$stage]) && $this->inspect($taxCase, $stage)['allowed']) {
                $accessible[] = $stage;
            }
        }

        $accessible = array_values(array_unique($accessible));
        sort($accessible);

        return $accessible;
    }

    private function loadEvidence(TaxCase $taxCase, ?int $stage = null): void
    {
        $relations = ['workflowHistories', 'status'];

        if ($stage === null || $stage === 5) {
            $relations[] = 'skpRecord';
        }
        if ($stage === null || $stage === 8) {
            $relations[] = 'objectionDecision';
        }
        if ($stage === null || $stage === 11) {
            $relations[] = 'appealDecision';
        }

        $taxCase->loadMissing($relations);
    }

    private function submittedStages(TaxCase $taxCase): array
    {
        return $taxCase->workflowHistories
            ->filter(fn ($history) => in_array(strtolower((string) $history->status), self::FINAL_STATUSES, true))
            ->whereBetween('stage_id', [1, 12])
            ->keyBy('stage_id')
            ->all();
    }

    private function continuesAfterStage4(TaxCase $taxCase): bool
    {
        if ($taxCase->skpRecord) {
            return $taxCase->skpRecord->continue_to_next_stage === true;
        }

        $history = $taxCase->workflowHistories
            ->where('stage_id', 4)
            ->filter(fn ($item) => in_array(strtolower((string) $item->status), self::FINAL_STATUSES, true))
            ->sortByDesc('id')
            ->first();
        $decision = $history?->decision_value ? json_decode($history->decision_value, true) : null;

        return is_array($decision) && ($decision['continue_to_next_stage'] ?? null) === true;
    }

    private function allowed(): array
    {
        return ['allowed' => true, 'status' => 200, 'message' => null];
    }

    private function denied(int $status, string $message): array
    {
        return ['allowed' => false, 'status' => $status, 'message' => $message];
    }
}
