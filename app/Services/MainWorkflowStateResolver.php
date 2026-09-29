<?php

namespace App\Services;

use App\Models\TaxCase;
use App\Services\TaxWorkflowMatrix\StageDefinitions;
use App\Services\TaxWorkflowMatrix\StageRoutingResolver;

class MainWorkflowStateResolver
{
    private const FINAL_STATUSES = ['submitted', 'approved', 'completed'];

    public function __construct(private StageRoutingResolver $routingResolver) {}

    /**
     * Resolve the complete Stage 1-12 state from persisted evidence.
     *
     * Callers resolving a collection should eager-load evidenceRelations() once.
     */
    public function resolve(TaxCase $taxCase): array
    {
        $taxCase->loadMissing(self::evidenceRelations());

        $evidence = [];
        $completed = [];
        $diagnostics = [];

        foreach (StageDefinitions::STAGES as $stage => $definition) {
            $evidence[$stage] = $this->completionEvidence($taxCase, $stage, $definition);
            $completed[$stage] = $evidence[$stage]['completed'];
            foreach ($evidence[$stage]['diagnostics'] as $diagnostic) {
                $diagnostics[] = ['stage' => $stage] + $diagnostic;
            }
        }

        $evidenceInconsistentAt = collect($evidence)->search(fn (array $item) => $item['inconsistent']);
        $sequenceInconsistentAt = $this->firstSequenceInconsistency($completed);
        $inconsistentFrom = $evidenceInconsistentAt !== false
            ? (int) $evidenceInconsistentAt
            : $sequenceInconsistentAt;
        if ($sequenceInconsistentAt !== null) {
            $diagnostics[] = [
                'stage' => $sequenceInconsistentAt,
                'code' => 'non_sequential_completion',
            ];
        }

        $closed = (bool) $taxCase->is_completed
            || strtoupper((string) $taxCase->status?->code) === 'CLOSED';
        $terminal = $closed || $completed[12];
        $stages = [];

        foreach (StageDefinitions::STAGES as $stage => $definition) {
            if ($completed[$stage]) {
                $stages[] = $this->stage($stage, $definition, 'completed', false, 'already_completed', $evidence[$stage]);

                continue;
            }

            if ($terminal) {
                $stages[] = $this->stage($stage, $definition, 'locked', false, 'case_terminal', $evidence[$stage]);

                continue;
            }

            if ($inconsistentFrom !== null) {
                $stages[] = $this->stage($stage, $definition, 'locked', false, 'inconsistent_persisted_state', $evidence[$stage]);

                continue;
            }

            $availability = $this->availability($taxCase, $stage, $completed);
            $stages[] = $this->stage(
                $stage,
                $definition,
                $availability['available'] ? 'available' : 'locked',
                $availability['available'],
                $availability['reason'],
                $evidence[$stage],
                $availability['diagnostics'] ?? []
            );
        }

        $completedStages = array_values(array_map('intval', array_keys(array_filter($completed))));
        $availableStages = array_values(array_map(
            fn (array $stage) => $stage['stage'],
            array_filter($stages, fn (array $stage) => $stage['available'])
        ));
        $currentStage = $availableStages[0] ?? ($completedStages ? max($completedStages) : 1);

        $diagnostics = array_values(array_merge($diagnostics, collect($stages)->flatMap(fn (array $stage) => array_map(
            fn (array $diagnostic) => ['stage' => $stage['stage']] + $diagnostic,
            $stage['diagnostics']
        ))->values()->all()));

        return [
            'current_stage' => max(1, min(12, $currentStage)),
            'terminal' => $terminal,
            'completed_stages' => $completedStages,
            'available_stages' => $availableStages,
            'stages' => $stages,
            'diagnostics' => $diagnostics,
        ];
    }

    public static function evidenceRelations(): array
    {
        return array_values(array_unique(array_merge(
            ['workflowHistories', 'status'],
            array_filter(array_column(StageDefinitions::STAGES, 'relation'))
        )));
    }

    private function completionEvidence(TaxCase $taxCase, int $stage, array $definition): array
    {
        $finalHistory = $taxCase->workflowHistories
            ->where('stage_id', $stage)
            ->filter(fn ($history) => in_array(strtolower((string) $history->status), self::FINAL_STATUSES, true))
            ->sortByDesc('id')
            ->first();
        $record = $definition['relation'] ? $taxCase->{$definition['relation']} : null;
        $recordStatus = $record && isset($record->status) ? strtolower((string) $record->status) : null;
        $recordFinal = in_array($recordStatus, self::FINAL_STATUSES, true);
        $hasDraftHistory = $taxCase->workflowHistories
            ->where('stage_id', $stage)
            ->contains(fn ($history) => strtolower((string) $history->status) === 'draft');
        $sources = [];
        $diagnostics = [];

        if ($finalHistory) {
            $sources[] = 'workflow_histories.status';
            if (strtolower((string) $finalHistory->status) !== 'submitted') {
                $diagnostics[] = [
                    'code' => 'legacy_status_value',
                    'value' => strtolower((string) $finalHistory->status),
                ];
            }
        }
        if ($recordFinal) {
            $sources[] = "{$definition['relation']}.status";
        }
        if ($recordFinal && ! $finalHistory) {
            $diagnostics[] = ['code' => 'record_final_without_final_history'];
        }
        if ($recordFinal && ! $finalHistory && $hasDraftHistory) {
            $diagnostics[] = ['code' => 'final_record_conflicts_with_draft_history'];
        }

        $inconsistent = collect($diagnostics)->contains(
            fn (array $diagnostic) => str_contains($diagnostic['code'], 'conflicts_with')
        );

        return [
            'completed' => ! $inconsistent && ((bool) $finalHistory || $recordFinal),
            'draft' => ! $finalHistory && ! $recordFinal && ($record !== null || $taxCase->workflowHistories->where('stage_id', $stage)->isNotEmpty()),
            'source' => $sources,
            'diagnostics' => $diagnostics,
            'inconsistent' => $inconsistent,
        ];
    }

    private function firstSequenceInconsistency(array $completed): ?int
    {
        foreach ($completed as $stage => $isCompleted) {
            if (! $isCompleted || $stage === 1) {
                continue;
            }
            for ($predecessor = 1; $predecessor < $stage; $predecessor++) {
                if (! $completed[$predecessor]) {
                    return $stage;
                }
            }
        }

        return null;
    }

    private function availability(TaxCase $taxCase, int $stage, array $completed): array
    {
        if ($stage === 1) {
            return ['available' => true, 'reason' => null];
        }
        if (! $completed[$stage - 1]) {
            return ['available' => false, 'reason' => 'previous_stage_incomplete'];
        }

        $route = match ($stage) {
            5 => $this->routingResolver->resolveAfterStage4($taxCase),
            8 => $this->routingResolver->resolveAfterStage7($taxCase),
            11 => $this->routingResolver->resolveAfterStage10($taxCase),
            default => null,
        };
        if ($route === null || $route['state'] === 'included') {
            return ['available' => true, 'reason' => null];
        }

        return [
            'available' => false,
            'reason' => $route['state'] === 'excluded' ? 'decision_did_not_continue' : 'inconsistent_persisted_state',
            'diagnostics' => $route['data_quality'] ?? [],
        ];
    }

    private function stage(
        int $stage,
        array $definition,
        string $status,
        bool $available,
        ?string $reason,
        array $evidence,
        array $diagnostics = []
    ): array {
        return [
            'stage' => $stage,
            'key' => $definition['key'],
            'status' => $status,
            'available' => $available,
            'submittable' => $available,
            'reason' => $reason,
            'draft' => $evidence['draft'],
            'source' => $evidence['source'],
            'diagnostics' => array_values(array_merge($evidence['diagnostics'], $diagnostics)),
        ];
    }
}
