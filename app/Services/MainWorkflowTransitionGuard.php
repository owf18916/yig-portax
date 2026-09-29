<?php

namespace App\Services;

use App\Models\TaxCase;

class MainWorkflowTransitionGuard
{
    public function __construct(private MainWorkflowStateResolver $stateResolver) {}

    public function inspect(TaxCase $taxCase, int $stage): array
    {
        if ($stage < 1 || $stage > 12) {
            return $this->denied(422, 'Main workflow stages must be between 1 and 12.');
        }

        $state = $this->stateResolver->resolve($taxCase);
        $resolved = collect($state['stages'])->firstWhere('stage', $stage);

        if ($resolved['submittable'] ?? false) {
            return $this->allowed();
        }

        return $this->denied(409, $this->message($stage, $resolved['reason'] ?? 'stage_locked'));
    }

    public function accessibleStages(TaxCase $taxCase): array
    {
        $state = $this->stateResolver->resolve($taxCase);

        return array_values(array_merge($state['completed_stages'], $state['available_stages']));
    }

    private function message(int $stage, string $reason): string
    {
        return match ($reason) {
            'already_completed' => "Stage {$stage} has already been submitted and cannot be rewritten through the normal workflow endpoint.",
            'case_terminal' => 'Completed or closed tax cases cannot accept workflow changes.',
            'previous_stage_incomplete' => 'The previous stage must be submitted first.',
            'decision_did_not_continue' => "Stage {$stage} is not included by the persisted Decision Point choice.",
            'inconsistent_persisted_state' => 'Persisted workflow evidence is inconsistent; the stage is locked for safety.',
            default => "Stage {$stage} is not currently submittable.",
        };
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
