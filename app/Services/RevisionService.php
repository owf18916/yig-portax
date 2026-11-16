<?php

namespace App\Services;

use App\Config\RevisionFieldConfig;
use App\Events\{RevisionApproved, RevisionRejected, RevisionRequested};
use App\Models\{Document, RefundProcess, Revision, TaxCase, User};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RevisionService
{
    private const FINAL_EVIDENCE = ['submitted', 'approved', 'completed'];
    private const DECISION_STAGES = [4, 7, 10, 12];
    private const NEXT_STAGE = [4 => 5, 7 => 8, 10 => 11];

    public function __construct(
        private DecisionPointRefundService $refundService,
        private KianNotificationService $kianService,
        private MainWorkflowStateResolver $stateResolver,
    ) {}

    public function requestRevision(
        TaxCase $taxCase,
        User $requestedBy,
        array $proposedValues,
        array $proposedDocumentChanges,
        string $reason,
        array $selectedFields,
        int|string $stageCode
    ): Revision {
        $stage = (int) $stageCode;
        $contract = RevisionFieldConfig::contract($stage);
        if (!$contract) throw ValidationException::withMessages(['stage_code' => 'Generic Revision supports main workflow stages 1 through 12 only.']);

        $target = $this->resolveTarget($taxCase, $stage, $contract);
        $this->assertSubmitted($taxCase, $target, $stage);
        $this->assertStageTwelveOpen($taxCase, $stage);
        $values = $this->validateValues($contract, $selectedFields, $proposedValues);
        if ($stage === 1) app(SptFilingContract::class)->validate($values, $taxCase);

        return DB::transaction(function () use ($taxCase, $target, $requestedBy, $values, $proposedDocumentChanges, $reason, $selectedFields, $stage) {
            // Serialize with account revocation and authorize a fresh actor, never
            // the session model that passed the earlier controller policy.
            $requestedBy = User::whereKey($requestedBy->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($requestedBy)->authorize('request', [new Revision(), $taxCase]);
            TaxCase::whereKey($taxCase->id)->lockForUpdate()->firstOrFail();
            $lockedTarget = $target::whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRevisionDocuments($taxCase, $stage, $proposedDocumentChanges, $lockedTarget);
            $this->normalizeRefundRevision($lockedTarget, $stage, $values);
            $duplicate = Revision::where('target_type', $target::class)->where('target_id', $target->getKey())
                ->where('stage_code', $stage)->where('revision_status', 'requested')->lockForUpdate()->exists();
            if ($duplicate) throw ValidationException::withMessages(['revision' => 'A pending revision already exists for this target and stage.']);

            $original = Arr::only($lockedTarget->getAttributes(), array_keys($values));
            $revision = Revision::create([
                'revisable_type' => 'TaxCase', 'revisable_id' => $taxCase->id,
                'target_type' => $target::class, 'target_id' => $target->getKey(), 'stage_code' => $stage,
                'target_version' => $this->version($lockedTarget), 'revision_status' => 'requested',
                'original_data' => $original, 'proposed_values' => $values,
                'proposed_document_changes' => $this->normalizeDocumentChanges($proposedDocumentChanges),
                'requested_by' => $requestedBy->id, 'requested_at' => now(), 'reason' => $reason,
            ]);
            event(new RevisionRequested($revision, $taxCase));
            return $revision->load('requestedBy');
        }, 3);
    }

    public function decideRevision(Revision $revision, TaxCase $taxCase, User $actor, string $decision, ?string $reason = null): Revision
    {
        return DB::transaction(function () use ($revision, $taxCase, $actor, $decision, $reason) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $case = TaxCase::whereKey($taxCase->id)->lockForUpdate()->firstOrFail();
            $locked = Revision::whereKey($revision->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('revisable', $case);
            Gate::forUser($actor)->authorize('decide', $locked);
            $locked->unsetRelation('revisable');
            if ((int)$locked->revisable_id !== (int)$case->id || !in_array($locked->revisable_type, [TaxCase::class, 'TaxCase'], true)) {
                throw new RuntimeException('Revision does not belong to this tax case.');
            }
            if (!$locked->isPending()) return $locked; // deterministic idempotent replay
            if ($decision === 'reject') return $this->reject($locked, $actor, $reason);

            $stage = (int)$locked->stage_code;
            $contract = RevisionFieldConfig::contract($stage);
            if (!$contract || !$locked->target_type || !$locked->target_id) throw new RuntimeException('Legacy or unsupported revision cannot be applied; request a new canonical revision.');
            if ($locked->target_type !== $contract['class']) throw new RuntimeException('Revision target type does not match its stage contract.');
            /** @var Model $target */
            $target = $contract['class']::whereKey($locked->target_id)->lockForUpdate()->firstOrFail();
            $this->assertTargetBelongs($case, $target, $stage);
            if (!hash_equals((string)$locked->target_version, $this->version($target))) throw new RuntimeException('Revision is stale because the target changed after the request.');
            $this->assertSubmitted($case, $target, $stage);
            $this->assertStageTwelveOpen($case, $stage);
            $values = $this->validateValues($contract, array_keys($locked->proposed_values ?? []), $locked->proposed_values ?? []);
            $this->normalizeRefundRevision($target, $stage, $values);
            $downstream = $this->assertNoDownstream($case, $stage);
            if (in_array($stage, [1, 4, 7, 10, 12], true)) {
                $merged = array_replace($target->getAttributes(), $values);
                $normalized = app(DecisionPointTaxExposure::class)->normalizeForCase($case, $stage, $merged);
                if (in_array($stage, [7, 10, 12], true) && (array_key_exists('decision_type', $values) || array_key_exists('decision_amount', $values))) $values['decision_amount'] = $normalized['decision_amount'];
            }
            if ($stage === 1) app(SptFilingContract::class)->validate($values, $case);
            $before = Arr::only($target->getAttributes(), array_keys($values));
            $refund = $this->prepareRefundReconciliation($case, $target, $stage, $values);

            if (isset(self::NEXT_STAGE[$stage]) && array_key_exists('continue_to_next_stage', $values)) {
                if ($target->hasAttribute('next_stage')) $values['next_stage'] = $values['continue_to_next_stage'] ? self::NEXT_STAGE[$stage] : null;
            }
            $target->fill($values)->save();
            $this->applyDocuments($locked, $case, $target);
            $refundResult = $this->applyRefundReconciliation($target, $stage, $actor->id, $refund);
            $kianResult = 'not_applicable';
            if (in_array($stage, self::DECISION_STAGES, true)) {
                $case->setRelation([4 => 'skpRecord', 7 => 'objectionDecision', 10 => 'appealDecision', 12 => 'supremeCourtDecision'][$stage], $target);
                $this->kianService->evaluateFinalSubmit($case, $stage, $locked);
                $kianResult = 'eligibility_re_evaluated; historical evidence preserved';
            }

            $target->refresh();
            $after = Arr::only($target->getAttributes(), array_keys($values));
            $audit = [
                'revision_id'=>$locked->id, 'target_type'=>$target::class, 'target_id'=>$target->getKey(), 'stage'=>$stage,
                'before'=>$before, 'after'=>$after, 'changed_fields'=>array_keys($values),
                'actor'=>$actor->id, 'reason'=>$locked->reason, 'downstream_check'=>$downstream,
                'routing_impact'=>$this->routingImpact($stage, $values), 'refund_reconciliation'=>$refundResult,
                'refund_authority'=>$refund['authority'] ?? null,
                'kian_reconciliation'=>$kianResult, 'target_version_before'=>$locked->target_version,
                'target_version_after'=>$this->version($target), 'applied_at'=>now()->toISOString(),
            ];
            $locked->update(['revision_status'=>'approved','approved_by'=>$actor->id,'approved_at'=>now(),'revised_data'=>$after,'audit_data'=>$audit]);
            event(new RevisionApproved($locked));
            return $locked->refresh();
        }, 3);
    }

    private function resolveTarget(TaxCase $case, int $stage, array $contract): Model
    {
        if ($stage === 1) return $case;
        $case->loadMissing($contract['relation']);
        $target = $case->{$contract['relation']};
        if (!$target) throw ValidationException::withMessages(['stage_code' => 'The concrete target record does not exist for this stage.']);
        $this->assertTargetBelongs($case, $target, $stage);
        return $target;
    }

    private function assertTargetBelongs(TaxCase $case, Model $target, int $stage): void
    {
        if ($stage === 1 ? ((int)$target->getKey() !== (int)$case->id) : ((int)$target->getAttribute('tax_case_id') !== (int)$case->id)) {
            throw new RuntimeException('Revision target does not belong to this tax case.');
        }
    }

    private function validateValues(array $contract, array $selected, array $values): array
    {
        if (!array_is_list($selected) || count($selected) !== count(array_unique($selected)) || collect($selected)->contains(fn ($field) => !is_string($field))) {
            throw ValidationException::withMessages(['fields' => 'Selected fields must be a unique list of canonical field keys.']);
        }
        $unknownValues = array_diff(array_keys($values), $contract['fields']);
        $unknownSelected = array_diff($selected, array_merge($contract['fields'], ['supporting_docs']));
        if ($unknownValues || $unknownSelected) throw ValidationException::withMessages(['proposed_values' => 'Unknown or forbidden revision fields: '.implode(', ', array_unique(array_merge($unknownValues, $unknownSelected)))]);
        $selectedBusiness = array_values(array_diff($selected, ['supporting_docs']));
        if (array_diff(array_keys($values), $selectedBusiness) || array_diff($selectedBusiness, array_keys($values))) {
            throw ValidationException::withMessages(['fields' => 'Selected fields and proposed values must match exactly.']);
        }
        foreach ($values as $field => &$value) {
            if ($value === '' && str_contains($contract['rules'][$field], 'nullable')) $value = null;
        }
        unset($value);
        return Validator::make($values, Arr::only($contract['rules'], array_keys($values)))->validate();
    }

    private function assertSubmitted(TaxCase $case, Model $target, int $stage): void
    {
        $history = $case->workflowHistories()->where('stage_id', $stage)->whereIn('status', self::FINAL_EVIDENCE)->exists();
        $status = strtolower((string)$target->getAttribute('status'));
        if (!$history && !in_array($status, self::FINAL_EVIDENCE, true)) throw ValidationException::withMessages(['stage_code' => 'The exact target stage has no submitted/completed evidence.']);
    }

    private function assertStageTwelveOpen(TaxCase $case, int $stage): void
    {
        if ($stage === 12 && ($case->is_completed || $this->stateResolver->resolve($case->fresh())['terminal'])) {
            throw ValidationException::withMessages(['stage_code' => 'Stage 12 cannot be revised after terminal completion.']);
        }
    }

    private function assertNoDownstream(TaxCase $case, int $stage): array
    {
        $state = $this->stateResolver->resolve($case->fresh());
        $downstream = array_values(array_filter($state['completed_stages'], fn($completed) => $completed > $stage));
        if ($downstream) throw new RuntimeException('Revision approval blocked because downstream stage(s) '.implode(', ', $downstream).' already have submitted/completed evidence.');
        return ['result'=>'clear','completed_downstream'=>[]];
    }

    private function prepareRefundReconciliation(TaxCase $case, Model $target, int $stage, array $values): array
    {
        if (!in_array($stage, self::DECISION_STAGES, true)) return ['action'=>'none','process'=>null];
        $beforeFlag = (bool)$target->getAttribute('create_refund');
        $afterFlag = array_key_exists('create_refund',$values) ? (bool)$values['create_refund'] : $beforeFlag;
        // Unrelated proposals never repair, retire or create financial records.
        if (!array_key_exists('refund_amount', $values) && $beforeFlag === $afterFlag) return ['action'=>'none','process'=>null];
        $beforeAmount = $this->refundService->amount($target->refund_amount, $beforeFlag);
        $afterAmount = $values['refund_amount'] ?? $beforeAmount;
        if ($beforeFlag === $afterFlag && $beforeAmount === $afterAmount) return ['action'=>'none','process'=>null];
        $processes = RefundProcess::withTrashed()->where(function ($query) use ($case, $target, $stage) {
            $query->where(fn ($q) => $q->where('tax_case_id', $case->id)->where('stage_id', $stage))
                ->orWhere(fn ($q) => $q->where('triggered_by_decision_type', $target::class)->where('triggered_by_decision_id', $target->id));
        })->orderBy('id')->limit(2)->lockForUpdate()->get();
        if ($processes->count() > 1 || $processes->first()?->trashed()) throw new RuntimeException('Historical or duplicate Refund requires separate review.');
        $process = $processes->first();
        if ($process && ((int)$process->tax_case_id !== (int)$case->id || (int)$process->stage_id !== $stage)) throw new RuntimeException('Refund source is linked to another case or stage.');
        if (!$beforeFlag) {
            if ($process) throw new RuntimeException('Existing Refund conflicts with the prior decision intent.');
            return ['action'=>$afterFlag ? 'create' : 'none','process'=>null];
        }
        if (!$process) throw new RuntimeException('The linked Refund is missing; separate review is required.');
        // Validate the old current authority and all existing payment evidence before changing it.
        $transfer = app(OrdinaryRefundPayment::class)->assertRevisionAuthority($case, $process);
        if ($process->triggered_by_decision_type !== $target::class || (int)$process->triggered_by_decision_id !== (int)$target->id) throw new RuntimeException('Refund source does not match the Revision target.');
        if (!$afterFlag) {
            if ($transfer || !$process->isRefundStage1() || $process->status !== 'draft' || $process->refund_status !== 'pending') throw new RuntimeException('Refund intent cannot change after payment activity.');
            return ['action'=>'reject_initial','process'=>$process];
        }
        return ['action'=>'sync_amount','process'=>$process,'amount'=>$afterAmount,'authority'=>[
            'refund_process_id'=>$process->id, 'transfer_id'=>$transfer?->id,
            'historical_amount'=>$transfer?->transfer_amount, 'before'=>$beforeAmount, 'after'=>$afterAmount,
        ]];
    }

    private function normalizeRefundRevision(Model $target, int $stage, array &$values): void
    {
        if (!in_array($stage, self::DECISION_STAGES, true)) return;
        $required = (bool)($values['create_refund'] ?? $target->create_refund);
        if (array_key_exists('refund_amount', $values)) $values['refund_amount'] = $this->refundService->amount($values['refund_amount'], $required);
        elseif ($required && !(bool)$target->create_refund) $this->refundService->amount($target->refund_amount, true);
    }

    private function applyRefundReconciliation(Model $target, int $stage, int $actor, array $plan): string
    {
        if ($plan['action']==='create') { $this->refundService->createIfRequested($target, $stage, $actor); return 'created_idempotently'; }
        if ($plan['action']==='sync_amount') {
            $this->refundService->assertStoredAmount($target, $plan['amount']);
            $plan['process']->update(['refund_amount'=>$plan['amount']]);
            if ($this->refundService->amount($plan['process']->fresh()->refund_amount, true) !== $plan['amount']) throw new RuntimeException('Refund Amount could not be stored exactly.');
            return 'current_amount_synchronized; historical_payment_preserved';
        }
        if ($plan['action']==='reject_initial') { $plan['process']->update(['refund_status'=>'rejected','status'=>'rejected','status_comment'=>'Reconciled by approved upstream revision']); return 'initial_process_rejected_preserving_history'; }
        return 'unchanged';
    }

    private function applyDocuments(Revision $revision, TaxCase $case, Model $target): void
    {
        $changes = $this->normalizeDocumentChanges($revision->proposed_document_changes ?? []);
        $this->assertRevisionDocuments($case, (int)$revision->stage_code, $changes, $target);
        if ($changes['files_to_delete']) Document::whereIn('id',$changes['files_to_delete'])->where('tax_case_id',$case->id)->where('stage_code',(string)$revision->stage_code)->delete();
        if ($changes['files_to_add']) Document::whereIn('id',$changes['files_to_add'])->where('tax_case_id',$case->id)->where('stage_code',(string)$revision->stage_code)->update(['documentable_type'=>$target::class,'documentable_id'=>$target->getKey()]);
    }

    public function assertRevisionDocuments(TaxCase $case, int $stage, array $changes, ?Model $target = null): void
    {
        $changes = $this->normalizeDocumentChanges($changes);
        $ids = array_values(array_unique(array_merge($changes['files_to_delete'], $changes['files_to_add'])));
        if (!$ids) return;
        if (!$target && ($contract = RevisionFieldConfig::contract($stage))) $target = $this->resolveTarget($case, $stage, $contract);
        // Canonical KIAN writers also lock case first. The persisted reverse link
        // covers retired evidence even if its morph owner is a legacy main owner.
        $linked = \App\Models\KianSubmission::withTrashed()->where('tax_case_id', $case->id)
            ->whereIn('response_document_id', $ids)->orderBy('id')->limit(1)->lockForUpdate()->get(['id']);
        abort_unless($linked->isEmpty(), 404, 'Document not found for this tax case.');
        $documents = Document::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($documents->count() === count($ids), 404, 'Document not found for this tax case.');
        foreach ($documents as $document) {
            $mainOwner = in_array($document->documentable_type, ['TaxCase', TaxCase::class], true)
                && (int)$document->documentable_id === (int)$case->id;
            // StageForm's established main-workflow upload owner is the stage code,
            // not a WorkflowHistory primary key. Preserve this scoped association.
            $mainOwner = $mainOwner || ($document->documentable_type === \App\Models\WorkflowHistory::class && (int)$document->documentable_id === $stage);
            if ($target) $mainOwner = $mainOwner || ($document->documentable_type === $target::class && (int)$document->documentable_id === (int)$target->getKey());
            abort_unless(!$document->trashed() && (int)$document->tax_case_id === (int)$case->id
                && (string)$document->stage_code === (string)$stage && $mainOwner
                && !in_array($document->documentable_type, ['KianSubmission', \App\Models\KianSubmission::class], true)
                && $document->document_type !== 'kian_final_response', 404, 'Document not found for this tax case.');
        }
    }

    private function normalizeDocumentChanges(array $changes): array
    {
        Validator::make($changes, ['files_to_delete'=>'sometimes|array', 'files_to_add'=>'sometimes|array', 'files_to_delete.*'=>'integer|min:1', 'files_to_add.*'=>'integer|min:1'])->validate();
        return ['files_to_delete'=>collect($changes['files_to_delete']??[])->map(fn($id)=>(int)$id)->filter()->unique()->values()->all(),'files_to_add'=>collect($changes['files_to_add']??[])->map(fn($id)=>(int)$id)->filter()->unique()->values()->all()];
    }

    private function reject(Revision $revision, User $actor, ?string $reason): Revision
    {
        $revision->update(['revision_status'=>'rejected','approved_by'=>$actor->id,'approved_at'=>now(),'rejection_reason'=>$reason]);
        event(new RevisionRejected($revision));
        return $revision->refresh();
    }

    private function routingImpact(int $stage, array $values): array
    {
        if (!isset(self::NEXT_STAGE[$stage]) || !array_key_exists('continue_to_next_stage',$values)) return ['changed'=>false];
        return ['changed'=>true,'next_stage'=>$values['continue_to_next_stage'] ? self::NEXT_STAGE[$stage] : null];
    }

    private function version(Model $target): string
    {
        $attributes = $target->getAttributes();
        ksort($attributes);
        return hash('sha256', json_encode($attributes, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE));
    }
}
