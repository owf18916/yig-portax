<?php

namespace App\Services\TaxWorkflowMatrix;

use App\Models\TaxCase;

class StageRoutingResolver
{
    public function resolveAfterStage4(TaxCase $taxCase): array
    {
        $record = $taxCase->skpRecord;
        $history = $this->latestSubmittedHistory($taxCase, 4);
        if ($route = $this->approvedRoute($taxCase, $record, 4, $history)) return $route;
        $signals = [];

        if ($record && $record->continue_to_next_stage !== null) {
            $signals[] = [
                'source' => 'skp_records.continue_to_next_stage',
                'value' => (bool) $record->continue_to_next_stage,
                'includes' => (bool) $record->continue_to_next_stage,
            ];
        }

        if ($history && $history->stage_to) {
            $signals[] = [
                'source' => 'workflow_histories.stage_to',
                'value' => $history->stage_to,
                'includes' => (int) $history->stage_to === 5,
                'supporting' => true,
            ];
        }

        return $this->resolveBooleanRoute($signals, true);
    }

    public function resolveAfterStage7(TaxCase $taxCase): array
    {
        $record = $taxCase->objectionDecision;
        $history = $this->latestSubmittedHistory($taxCase, 7);
        if ($route = $this->approvedRoute($taxCase, $record, 7, $history)) return $route;
        $signals = [];

        if ($record && $record->continue_to_next_stage !== null) {
            $signals[] = [
                'source' => 'objection_decisions.continue_to_next_stage',
                'value' => (bool) $record->continue_to_next_stage,
                'includes' => (bool) $record->continue_to_next_stage,
            ];
        }

        if ($record && $record->next_stage !== null) {
            $signals[] = [
                'source' => 'objection_decisions.next_stage',
                'value' => (int) $record->next_stage,
                'includes' => (int) $record->next_stage === 8,
            ];
        }

        if ($history && $history->stage_to) {
            $signals[] = [
                'source' => 'workflow_histories.stage_to',
                'value' => (int) $history->stage_to,
                'includes' => (int) $history->stage_to === 8,
            ];
        }

        $decisionValue = $this->decodeDecisionValue($history?->decision_value);
        if (isset($decisionValue['user_routing_choice'])) {
            $choice = $decisionValue['user_routing_choice'];
            $signals[] = [
                'source' => 'workflow_histories.decision_value.user_routing_choice',
                'value' => $choice,
                'includes' => in_array($choice, ['appeal', 'continue', 'next_stage'], true),
            ];
        }

        if ($record && $record->decision_type === 'partially_granted' && empty($signals)) {
            return $this->unfinalized('ambiguous_route', []);
        }

        return $this->resolveBooleanRoute($signals, true);
    }

    public function resolveAfterStage10(TaxCase $taxCase): array
    {
        $record = $taxCase->appealDecision;
        $history = $this->latestSubmittedHistory($taxCase, 10);
        if ($route = $this->approvedRoute($taxCase, $record, 10, $history)) return $route;
        $signals = [];

        if ($record && $record->continue_to_next_stage !== null) {
            $signals[] = [
                'source' => 'appeal_decisions.continue_to_next_stage',
                'value' => (bool) $record->continue_to_next_stage,
                'includes' => (bool) $record->continue_to_next_stage,
            ];
        }

        if ($record && $record->next_stage !== null) {
            $signals[] = [
                'source' => 'appeal_decisions.next_stage',
                'value' => (int) $record->next_stage,
                'includes' => (int) $record->next_stage === 11,
            ];
        }

        if ($history && $history->stage_to) {
            $signals[] = [
                'source' => 'workflow_histories.stage_to',
                'value' => (int) $history->stage_to,
                'includes' => (int) $history->stage_to === 11,
                'supporting' => true,
            ];
        }

        return $this->resolveBooleanRoute($signals, true);
    }

    public function hasRefundIntent(TaxCase $taxCase): array
    {
        $signals = [];
        foreach ([4 => 'skpRecord', 7 => 'objectionDecision', 10 => 'appealDecision', 12 => 'supremeCourtDecision'] as $stage => $relation) {
            $record = $taxCase->{$relation};
            if ($record && (bool) data_get($record, 'create_refund')) {
                $signals[] = [
                    'source' => "{$relation}.create_refund",
                    'stage_id' => $stage,
                    'amount' => data_get($record, 'refund_amount'),
                ];
            }
        }

        return $signals;
    }

    private function approvedRoute(TaxCase $case, ?\Illuminate\Database\Eloquent\Model $record, int $stage, ?object $history): ?array
    {
        if (!$record || !$record->exists) return null;
        // One cached/eager-loaded relation for all three stages. Original
        // submission history remains evidence, but is superseded only by an
        // explicit, applied amendment for this exact current target and route.
        $revision = $case->approvedRoutingRevisions->firstWhere('stage_code', $stage);
        if (!$revision || $revision->revision_status !== 'approved'
            || ($revision->audit_data['routing_impact']['changed'] ?? null) !== true
            || $revision->target_type !== $record::class
            || (int)$revision->target_id !== (int)$record->getKey()
            || !$revision->approved_at || !$revision->approved_by
            || ($history?->created_at && $history->created_at->gt($revision->approved_at))) return null;
        $value = $revision->revised_data['continue_to_next_stage'] ?? null;
        if (!in_array($value, [true, false, 0, 1], true)
            || (bool)$value !== (bool)$record->continue_to_next_stage
            || (bool)$value !== (bool)($revision->proposed_values['continue_to_next_stage'] ?? null)
            || ($revision->audit_data['target_type'] ?? null) !== $record::class
            || (int)($revision->audit_data['target_id'] ?? 0) !== (int)$record->getKey()
            || ($revision->audit_data['downstream_check']['result'] ?? null) !== 'clear'
            || ($revision->audit_data['routing_impact']['next_stage'] ?? null) !== ($value ? $stage + 1 : null)
            || ($record->hasAttribute('next_stage') && $record->next_stage !== ($value ? $stage + 1 : null))) return null;

        return $this->resolveBooleanRoute([[
            'source' => 'revisions.approved_routing', 'revision_id' => $revision->id,
            'value' => (bool)$value, 'includes' => (bool)$value,
        ]], true);
    }

    private function resolveBooleanRoute(array $signals, bool $allowExplicitExclusion): array
    {
        if (empty($signals)) {
            return $this->unfinalized('ambiguous_route', []);
        }

        $includes = collect($signals)->where('includes', true)->values();
        $excludes = collect($signals)->where('includes', false)->values();

        if ($includes->isNotEmpty() && $excludes->isNotEmpty()) {
            return $this->unfinalized('conflicting_route', $signals);
        }

        if ($includes->isNotEmpty()) {
            return [
                'state' => 'included',
                'reason' => null,
                'data_quality' => [],
                'source' => $signals,
            ];
        }

        if ($allowExplicitExclusion && $excludes->isNotEmpty()) {
            return [
                'state' => 'excluded',
                'reason' => 'Workflow branch was explicitly excluded',
                'data_quality' => [],
                'source' => $signals,
            ];
        }

        return $this->unfinalized('ambiguous_route', $signals);
    }

    private function unfinalized(string $code, array $signals): array
    {
        return [
            'state' => 'unfinalized',
            'reason' => 'Workflow routing has not been finalized',
            'data_quality' => [['code' => $code, 'source' => $signals]],
            'source' => $signals,
        ];
    }

    private function latestSubmittedHistory(TaxCase $taxCase, int $stageId): ?object
    {
        return $taxCase->workflowHistories
            ->where('stage_id', $stageId)
            ->filter(fn ($history) => in_array(strtolower((string) $history->status), ['submitted', 'approved', 'completed'], true))
            ->sortBy([
                ['created_at', 'desc'],
                ['id', 'desc'],
            ])
            ->first();
    }

    private function decodeDecisionValue(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
