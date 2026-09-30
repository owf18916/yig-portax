<?php

namespace App\Services;

use App\Config\RevisionFieldConfig;
use App\Events\{RevisionApproved, RevisionRejected, RevisionRequested};
use App\Models\{Document, RefundProcess, Revision, TaxCase, User};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

        return DB::transaction(function () use ($taxCase, $target, $requestedBy, $values, $proposedDocumentChanges, $reason, $selectedFields, $stage) {
            TaxCase::whereKey($taxCase->id)->lockForUpdate()->firstOrFail();
            $lockedTarget = $target::whereKey($target->getKey())->lockForUpdate()->firstOrFail();
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
            $case = TaxCase::whereKey($taxCase->id)->lockForUpdate()->firstOrFail();
            $locked = Revision::whereKey($revision->id)->lockForUpdate()->firstOrFail();
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
            $downstream = $this->assertNoDownstream($case, $stage);
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
                $this->kianService->evaluateFinalSubmit($case->fresh(), $stage);
                $kianResult = 'eligibility_re_evaluated; historical evidence preserved';
            }

            $target->refresh();
            $after = Arr::only($target->getAttributes(), array_keys($values));
            $audit = [
                'revision_id'=>$locked->id, 'target_type'=>$target::class, 'target_id'=>$target->getKey(), 'stage'=>$stage,
                'before'=>$before, 'after'=>$after, 'changed_fields'=>array_keys($values),
                'actor'=>$actor->id, 'reason'=>$locked->reason, 'downstream_check'=>$downstream,
                'routing_impact'=>$this->routingImpact($stage, $values), 'refund_reconciliation'=>$refundResult,
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
        $process = RefundProcess::where('tax_case_id',$case->id)->where('stage_id',$stage)->lockForUpdate()->first();
        $beforeFlag = (bool)$target->getAttribute('create_refund');
        $afterFlag = array_key_exists('create_refund',$values) ? (bool)$values['create_refund'] : $beforeFlag;
        $beforeAmount = (string)$target->getAttribute('refund_amount');
        $afterAmount = array_key_exists('refund_amount',$values) ? (string)$values['refund_amount'] : $beforeAmount;
        if ($process && (!$afterFlag || ($afterAmount !== $beforeAmount && (string)$process->refund_amount !== $afterAmount))) {
            if (!$process->isRefundStage1() || $process->status !== 'draft' || $process->refund_status !== 'pending') throw new RuntimeException('Refund reconciliation is blocked because the RefundProcess has progressed beyond the editable boundary.');
        }
        if ($process && !$afterFlag) return ['action'=>'reject_initial','process'=>$process];
        if ($process && $afterAmount !== (string)$process->refund_amount) return ['action'=>'sync_amount','process'=>$process,'amount'=>$afterAmount];
        if (!$process && $afterFlag) return ['action'=>'create','process'=>null];
        return ['action'=>'none','process'=>$process];
    }

    private function applyRefundReconciliation(Model $target, int $stage, int $actor, array $plan): string
    {
        if ($plan['action']==='create') { $this->refundService->createIfRequested($target, $stage, $actor); return 'created_idempotently'; }
        if ($plan['action']==='sync_amount') { $plan['process']->update(['refund_amount'=>$plan['amount']]); return 'initial_amount_synchronized'; }
        if ($plan['action']==='reject_initial') { $plan['process']->update(['refund_status'=>'rejected','status'=>'rejected','status_comment'=>'Reconciled by approved upstream revision']); return 'initial_process_rejected_preserving_history'; }
        return 'unchanged';
    }

    private function applyDocuments(Revision $revision, TaxCase $case, Model $target): void
    {
        $changes = $this->normalizeDocumentChanges($revision->proposed_document_changes ?? []);
        if ($changes['files_to_delete']) Document::whereIn('id',$changes['files_to_delete'])->where('tax_case_id',$case->id)->where('stage_code',(string)$revision->stage_code)->delete();
        if ($changes['files_to_add']) Document::whereIn('id',$changes['files_to_add'])->where('tax_case_id',$case->id)->where('stage_code',(string)$revision->stage_code)->update(['documentable_type'=>$target::class,'documentable_id'=>$target->getKey()]);
    }

    private function normalizeDocumentChanges(array $changes): array
    {
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
