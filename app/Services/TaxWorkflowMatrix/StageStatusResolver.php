<?php

namespace App\Services\TaxWorkflowMatrix;

use App\Models\TaxCase;
use App\Services\MainWorkflowStateResolver;

/** Dashboard presentation adapter; workflow rules live in MainWorkflowStateResolver. */
class StageStatusResolver
{
    private MainWorkflowStateResolver $mainWorkflowStateResolver;

    public function __construct(StageRoutingResolver $routingResolver)
    {
        $this->mainWorkflowStateResolver = new MainWorkflowStateResolver($routingResolver);
    }

    public function resolveAll(TaxCase $taxCase): array
    {
        $canonical = $this->mainWorkflowStateResolver->resolve($taxCase);
        $resolved = [];

        foreach ($canonical['stages'] as $stage) {
            $definition = StageDefinitions::STAGES[$stage['stage']];
            $status = match (true) {
                $stage['status'] === 'completed' => 'completed',
                $stage['available'] && $stage['draft'] => 'draft',
                $stage['available'] => 'action_required',
                $stage['reason'] === 'decision_did_not_continue' => 'not_applicable',
                default => 'not_available_yet',
            };
            $clickable = $stage['status'] === 'completed' || $stage['available'];

            $resolved[$definition['key']] = [
                'stage' => $definition['key'],
                'stage_id' => $stage['stage'],
                'status' => $status,
                'label' => $this->label($status),
                'clickable' => $clickable,
                'action' => $clickable ? [
                    'type' => 'route',
                    'name' => $definition['route'],
                    'params' => ['id' => $taxCase->id],
                    'query' => [],
                ] : null,
                'reason' => $stage['reason'],
                'source' => $stage['source'],
                'data_quality' => $stage['diagnostics'],
                'revision' => null,
            ];
        }

        return $resolved;
    }

    /** @deprecated Use MainWorkflowStateResolver::resolve(). */
    public function effectiveStatus(TaxCase $taxCase, int $stageId, ?array $definition = null): array
    {
        $stage = collect($this->mainWorkflowStateResolver->resolve($taxCase)['stages'])
            ->firstWhere('stage', $stageId);

        return [
            'status' => $stage['status'] === 'completed' ? 'completed' : ($stage['draft'] ? 'draft' : 'missing'),
            'source' => $stage['source'],
            'data_quality' => $stage['diagnostics'],
        ];
    }

    private function label(string $status): string
    {
        return match ($status) {
            'completed' => 'Completed',
            'draft' => 'Draft',
            'action_required' => 'Input Required',
            'not_applicable' => 'Not Applicable',
            default => 'Not Available',
        };
    }
}
