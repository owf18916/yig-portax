<?php

namespace App\Services\TaxWorkflowMatrix;

use App\Models\TaxCase;

class StageStatusResolver
{
    private const FINAL_STATUSES = ['submitted', 'approved', 'completed'];

    public function __construct(private StageRoutingResolver $routingResolver)
    {
    }

    public function resolveAll(TaxCase $taxCase): array
    {
        $resolved = [];
        $branch = [
            'objection' => 'unknown',
            'appeal' => 'unknown',
            'supreme' => 'unknown',
        ];

        foreach (StageDefinitions::STAGES as $stageId => $definition) {
            $effective = $this->effectiveStatus($taxCase, $stageId, $definition);

            if ($effective['status'] === 'completed') {
                $resolved[$definition['key']] = $this->cell($definition, $stageId, 'completed', true, $taxCase, null, $effective);
                continue;
            }

            if ($effective['status'] === 'draft') {
                $resolved[$definition['key']] = $this->cell($definition, $stageId, 'draft', true, $taxCase, null, $effective);
                continue;
            }

            $availability = $this->availability($taxCase, $stageId, $resolved, $branch);
            $resolved[$definition['key']] = $this->cell(
                $definition,
                $stageId,
                $availability['status'],
                $availability['status'] === 'action_required',
                $taxCase,
                $availability['reason'],
                [
                    'source' => $availability['source'] ?? [],
                    'data_quality' => $availability['data_quality'] ?? [],
                ]
            );

            if ($stageId === 5 && $availability['status'] === 'not_applicable') {
                $branch['objection'] = 'excluded';
            }
            if ($stageId === 8 && $availability['status'] === 'not_applicable') {
                $branch['appeal'] = 'excluded';
            }
            if ($stageId === 11 && $availability['status'] === 'not_applicable') {
                $branch['supreme'] = 'excluded';
            }
        }

        return $resolved;
    }

    public function effectiveStatus(TaxCase $taxCase, int $stageId, ?array $definition = null): array
    {
        $definition ??= StageDefinitions::STAGES[$stageId];
        $record = $definition['relation'] ? $taxCase->{$definition['relation']} : $taxCase;
        $history = $this->latestHistory($taxCase, $stageId);
        $source = [];
        $dataQuality = [];

        if ($record && !in_array($stageId, [1, 2, 3], true) && isset($record->status)) {
            $recordStatus = strtolower((string) $record->status);
            if ($recordStatus === 'submitted') {
                return ['status' => 'completed', 'source' => ["{$definition['relation']}.status"], 'data_quality' => []];
            }
            if (in_array($recordStatus, ['approved', 'completed'], true)) {
                return [
                    'status' => 'completed',
                    'source' => ["{$definition['relation']}.status"],
                    'data_quality' => [['code' => 'legacy_status_value', 'value' => $recordStatus]],
                ];
            }
            if ($recordStatus === 'draft') {
                $source[] = "{$definition['relation']}.status";
            }
        }

        if ($history) {
            $historyStatus = strtolower((string) $history->status);
            if ($historyStatus === 'submitted') {
                return ['status' => 'completed', 'source' => ['workflow_histories.status'], 'data_quality' => []];
            }
            if (in_array($historyStatus, ['approved', 'completed'], true)) {
                return [
                    'status' => 'completed',
                    'source' => ['workflow_histories.status'],
                    'data_quality' => [['code' => 'legacy_status_value', 'value' => $historyStatus]],
                ];
            }
            if ($historyStatus === 'draft') {
                $source[] = 'workflow_histories.status';
            }
        }

        if ($record || $history) {
            if (in_array($stageId, [2, 3], true) && $record && !$history) {
                $dataQuality[] = ['code' => 'ambiguous_finality'];
            }

            return ['status' => 'draft', 'source' => $source ?: ['stage_record'], 'data_quality' => $dataQuality];
        }

        return ['status' => 'missing', 'source' => [], 'data_quality' => []];
    }

    private function availability(TaxCase $taxCase, int $stageId, array $resolved, array $branch): array
    {
        if ($stageId === 1) {
            return ['status' => 'action_required', 'reason' => null];
        }

        $previous = StageDefinitions::STAGES[$stageId - 1] ?? null;
        $previousStatus = $previous ? ($resolved[$previous['key']]['status'] ?? null) : null;

        if ($stageId === 5) {
            return $this->branchAvailability($this->routingResolver->resolveAfterStage4($taxCase), $previousStatus);
        }
        if (in_array($stageId, [6, 7], true) && $branch['objection'] === 'excluded') {
            return ['status' => 'not_applicable', 'reason' => 'Objection branch was not entered'];
        }
        if ($stageId === 8) {
            return $this->branchAvailability($this->routingResolver->resolveAfterStage7($taxCase), $previousStatus);
        }
        if (in_array($stageId, [9, 10], true) && $branch['appeal'] === 'excluded') {
            return ['status' => 'not_applicable', 'reason' => 'Appeal branch was not entered'];
        }
        if ($stageId === 11) {
            return $this->branchAvailability($this->routingResolver->resolveAfterStage10($taxCase), $previousStatus);
        }
        if ($stageId === 12 && $branch['supreme'] === 'excluded') {
            return ['status' => 'not_applicable', 'reason' => 'Supreme Court branch was not entered'];
        }

        if ($previousStatus === 'completed') {
            return ['status' => 'action_required', 'reason' => null];
        }

        return ['status' => 'not_available_yet', 'reason' => 'Previous stage must be completed first'];
    }

    private function branchAvailability(array $route, ?string $previousStatus): array
    {
        if ($route['state'] === 'excluded') {
            return [
                'status' => 'not_applicable',
                'reason' => $route['reason'],
                'source' => $route['source'],
                'data_quality' => $route['data_quality'],
            ];
        }

        if ($route['state'] === 'unfinalized') {
            return [
                'status' => 'not_available_yet',
                'reason' => $route['reason'],
                'source' => $route['source'],
                'data_quality' => $route['data_quality'],
            ];
        }

        if ($previousStatus === 'completed') {
            return ['status' => 'action_required', 'reason' => null, 'source' => $route['source'], 'data_quality' => []];
        }

        return ['status' => 'not_available_yet', 'reason' => 'Previous stage must be completed first', 'source' => $route['source'], 'data_quality' => []];
    }

    private function cell(array $definition, int $stageId, string $status, bool $clickable, TaxCase $taxCase, ?string $reason, array $meta): array
    {
        return [
            'stage' => $definition['key'],
            'stage_id' => $stageId,
            'status' => $status,
            'label' => $this->label($status),
            'clickable' => $clickable,
            'action' => $clickable ? [
                'type' => 'route',
                'name' => $definition['route'],
                'params' => ['id' => $taxCase->id],
                'query' => [],
            ] : null,
            'reason' => $reason,
            'source' => $meta['source'] ?? [],
            'data_quality' => $meta['data_quality'] ?? [],
            'revision' => null,
        ];
    }

    private function latestHistory(TaxCase $taxCase, int $stageId): ?object
    {
        return $taxCase->workflowHistories
            ->where('stage_id', $stageId)
            ->sortBy([
                ['created_at', 'desc'],
                ['id', 'desc'],
            ])
            ->first();
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
