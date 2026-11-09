<?php

namespace App\Services;

use App\Models\BankTransferRequest;
use App\Models\CaseStatus;
use App\Models\PreliminaryRefundRequest;
use App\Models\RefundProcess;
use App\Models\StatusHistory;
use App\Models\TaxCase;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PreliminaryRefundLifecycle
{
    public static function concerns(TaxCase $case, ?RefundProcess $refund = null): bool
    {
        return $case->spt_type === 'Pengembalian Pendahuluan'
            || ($refund && ((string) $refund->stage_id === '0' || $refund->stage_source === 'PRELIMINARY'));
    }

    public function money(mixed $value): BigDecimal
    {
        try {
            $this->check((is_string($value) || is_int($value)) && preg_match('/^\d+(?:\.\d{1,2})?$/D', (string) $value), 'Use an exact decimal amount with at most two decimal places.');

            return BigDecimal::of($value)->toScale(2);
        } catch (MathException) {
            $this->check(false, 'Invalid decimal amount.');
        }
    }

    public function check(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['preliminary_refund' => $message]);
        }
    }

    public function eligible(TaxCase $case): void
    {
        Gate::authorize('update', $case);
        $this->check($case->case_type === 'VAT' && $case->spt_position_type === 'LB' && $case->spt_type === 'Pengembalian Pendahuluan', 'Only explicit VAT LB preliminary cases are eligible.');
        $state = app(MainWorkflowStateResolver::class)->resolve($case);
        $this->check(in_array(1, $state['completed_stages'], true), 'Submit Stage 1 before the administrative request.');
        $this->check(! collect($state['completed_stages'])->contains(fn ($stage) => $stage > 1), 'Existing audit-stage evidence requires separate review.');
    }

    public function open(TaxCase $case): void
    {
        $this->check(! $case->is_completed && $case->status?->code !== 'CLOSED', 'The case is closed.');
    }

    // All writers lock case first, then request, Refunds, transfers, in ID order.
    public function locked(int $caseId, callable $operation): mixed
    {
        return DB::transaction(function () use ($caseId, $operation) {
            $case = TaxCase::whereKey($caseId)->lockForUpdate()->firstOrFail();
            $this->eligible($case);

            return $operation($case);
        });
    }

    public function submit(int $caseId, array $data, \Illuminate\Http\UploadedFile $file): PreliminaryRefundRequest
    {
        $case = TaxCase::findOrFail($caseId);
        Gate::authorize('recordPreliminaryDecision', $case);
        validator($data, ['submission_date' => 'required|date_format:Y-m-d', 'requested_amount' => 'required', 'notes' => 'nullable|string|max:5000', 'request_number' => 'nullable|string|max:255'])->validate();
        $money = $this->money($data['requested_amount']);

        return app(PreliminaryRefundEvidence::class)->record($file, $caseId, function ($metadata) use ($caseId, $data, $money) {
            return $this->locked($caseId, function ($case) use ($data, $money, $metadata) {
                Gate::authorize('recordPreliminaryDecision', $case);
                $evidence = app(PreliminaryRefundEvidence::class);
                $request = PreliminaryRefundRequest::withTrashed()->where('tax_case_id', $case->id)->lockForUpdate()->first();
                if ($request) {
                    $this->check(! $request->trashed() && $evidence->matches($request->submissionDocument, $request, 'submission', $metadata)
                        && $request->submission_date?->toDateString() === $data['submission_date']
                        && $money->isEqualTo($this->money($request->requested_amount))
                        && $request->notes === ($data['notes'] ?? null)
                        && (! isset($data['request_number']) || $request->request_number === $data['request_number']), 'Conflicting submission retry or historical request; evidence cannot be replaced.');

                    return [$request, false];
                }
                $this->open($case);
                $this->check(! $case->refundProcesses()->withTrashed()->exists(), 'Existing Refund evidence requires review.');
                $this->check($money->isGreaterThan(0) && $money->isLessThanOrEqualTo($this->money($case->disputed_amount)), 'Request must be positive and within filed LB amount.');
                $request = PreliminaryRefundRequest::create([
                    'tax_case_id' => $case->id, 'request_number' => $data['request_number'] ?? 'PRR-'.$case->id,
                    'submission_date' => $data['submission_date'], 'requested_amount' => (string) $money,
                    'notes' => $data['notes'] ?? null, 'approval_status' => 'SUBMITTED',
                    'submitted_by' => auth()->id(), 'submitted_at' => now(),
                ]);
                $document = $evidence->attach($request, 'submission', $metadata);
                $request->update(['submission_document_id' => $document->id]);

                return [$request, true];
            });
        });
    }

    public function decide(int $caseId, int $requestId, string $decision, mixed $amount = null, ?string $reason = null, ?string $date = null, ?\Illuminate\Http\UploadedFile $file = null): PreliminaryRefundRequest
    {
        Gate::authorize('recordPreliminaryDecision', TaxCase::findOrFail($caseId));
        $this->check(in_array($decision, ['approve', 'reject'], true), 'Only APPROVED or REJECTED may be recorded; historical outcomes cannot be amended.');
        validator(['decision_date' => $date, 'document' => $file, 'reason' => $reason], [
            'decision_date' => 'required|date_format:Y-m-d', 'document' => 'required|file',
            'reason' => $decision === 'reject' ? 'required|string|min:10|max:5000' : 'nullable|string|max:5000',
        ])->validate();
        $money = $decision === 'approve' ? $this->money($amount) : null;

        return app(PreliminaryRefundEvidence::class)->record($file, $caseId, function ($metadata) use ($caseId, $requestId, $decision, $money, $reason, $date) {
            return $this->locked($caseId, function ($case) use ($requestId, $decision, $money, $reason, $date, $metadata) {
                Gate::authorize('recordPreliminaryDecision', $case);
                $request = PreliminaryRefundRequest::where('tax_case_id', $case->id)->whereKey($requestId)->lockForUpdate()->firstOrFail();
                $refunds = RefundProcess::withTrashed()->where('tax_case_id', $case->id)->orderBy('id')->lockForUpdate()->get();
                $evidence = app(PreliminaryRefundEvidence::class);
                $this->check($evidence->matches($request->submissionDocument, $request, 'submission'), 'Historical request without verified submission evidence: read-only; separate review required.');
                if ($money) {
                    $this->check($money->isGreaterThan(0) && $money->isLessThanOrEqualTo($this->money($request->requested_amount)), 'Approval must be positive and cannot exceed the requested amount.');
                }
                $status = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
                if ($request->approval_status === $status) {
                    $this->check($request->decision_date?->toDateString() === $date && $request->status_comment === $reason
                        && $evidence->matches($request->decisionDocument, $request, 'decision', $metadata)
                        && (! $money || $money->isEqualTo($this->money($request->approved_amount))), 'Conflicting decision retry; recorded evidence cannot be replaced.');
                    if ($money) {
                        $this->owned($case, $request, $refunds);
                    } else {
                        $this->check($refunds->isEmpty(), 'Rejected request has conflicting Refund evidence.');
                    }

                    return [$request, false];
                }
                $this->open($case);
                $this->check($request->approval_status === 'SUBMITTED' && ! $request->decision_document_id && ! $request->approved_at && $refunds->isEmpty(), 'Only a documented SUBMITTED request can receive its first decision. Historical PENDING/POSTPONED and terminal outcomes are read-only; separate review required.');
                $document = $evidence->attach($request, 'decision', $metadata);
                $request->update(['approval_status' => $status, 'decision_document_id' => $document->id, 'decision_date' => $date,
                    'approved_amount' => $money ? (string) $money : '0.00', 'approved_date' => $money ? $date : null,
                    'status_comment' => $reason, 'approved_by' => auth()->id(), 'approved_at' => now()]);
                if ($money) {
                    RefundProcess::create([
                        'tax_case_id' => $case->id, 'stage_id' => 0, 'stage_source' => 'PRELIMINARY',
                        'refund_number' => 'PRF-'.$request->id, 'refund_method' => 'bank_transfer',
                        'refund_amount' => (string) $money, 'refund_status' => 'pending', 'status' => 'approved',
                        'sequence_number' => 1, 'triggered_by_decision_type' => PreliminaryRefundRequest::class,
                        'triggered_by_decision_id' => $request->id, 'submitted_by' => $request->submitted_by,
                        'submitted_at' => now(), 'approved_by' => auth()->id(), 'approved_at' => now(),
                    ]);
                }

                return [$request, true];
            });
        });
    }

    private function owned(TaxCase $case, PreliminaryRefundRequest $request, $refunds): RefundProcess
    {
        $this->check(! $request->trashed() && $request->isApproved() && $request->approved_by && $request->approved_at, 'A recorded approved Tax Office decision is required.');
        $this->check($refunds->count() === 1, 'Ambiguous Refund evidence; one owned Refund is required.');
        $refund = $refunds->first();
        $this->check(! $refund->trashed() && (string) $refund->stage_id === '0' && $refund->stage_source === 'PRELIMINARY'
            && $refund->triggered_by_decision_type === PreliminaryRefundRequest::class
            && (int) $refund->triggered_by_decision_id === $request->id
            && strtolower($refund->refund_method) === 'bank_transfer', 'Refund ownership or method conflicts with the approved request.');
        $approved = $this->money($request->approved_amount);
        $this->check($approved->isGreaterThan(0) && $approved->isEqualTo($this->money($refund->refund_amount)), 'Refund amount conflicts with administrative approval.');

        return $refund;
    }

    public function payment(int $caseId, int $refundId, int $stage, array $data): BankTransferRequest
    {
        $this->check(in_array($stage, [2, 3, 4], true), 'Unsupported preliminary payment transition.');
        $rules = match ($stage) {
            2 => ['request_number' => 'required|string|max:255', 'request_date' => 'required|date', 'transfer_date' => 'required|date'],
            3 => ['instruction_number' => 'required|string|max:255', 'instruction_issue_date' => 'required|date', 'instruction_received_date' => 'required|date',
                'bank_code' => 'nullable|string', 'bank_name' => 'nullable|string', 'account_number' => 'nullable|string',
                'account_holder' => 'nullable|string', 'account_name' => 'nullable|string', 'transfer_amount' => 'nullable'],
            4 => ['receipt_number' => 'required|string|max:255', 'received_date' => 'required|date_format:Y-m-d', 'received_amount' => 'required'],
        };
        $data = validator($data, $rules + ['notes' => 'nullable|string'])->validate();

        return $this->locked($caseId, function ($case) use ($refundId, $stage, $data) {
            $request = PreliminaryRefundRequest::withTrashed()->where('tax_case_id', $case->id)->lockForUpdate()->first();
            $this->check($request !== null, 'A recorded approved Tax Office decision is required.');
            $refunds = RefundProcess::withTrashed()->where('tax_case_id', $case->id)->orderBy('id')->lockForUpdate()->get();
            $refund = $this->owned($case, $request, $refunds);
            $this->check($refund->id === $refundId, 'Select the owned preliminary Refund explicitly.');
            $transfers = BankTransferRequest::withTrashed()->where('refund_process_id', $refund->id)->orderBy('id')->lockForUpdate()->get();
            $this->check($transfers->count() <= 1, 'Multiple transfers require separate review; split settlement is unsupported.');
            $transfer = $transfers->first();
            if ($stage === 2) {
                $this->open($case);
                $this->check(! $transfer && strtolower($refund->refund_status) === 'pending', 'One transfer only; existing evidence cannot be replaced.');
                $transfer = BankTransferRequest::create($data + ['refund_process_id' => $refundId, 'transfer_number' => 'TRN-PRF-'.$refundId, 'transfer_amount' => $request->approved_amount, 'transfer_status' => 'pending', 'created_by' => auth()->id()]);
                $refund->update(['refund_status' => 'approved']);

                return $transfer;
            }
            $this->check($transfer && ! $transfer->trashed(), 'One active transfer request is required.');
            $this->check($this->money($transfer->transfer_amount)->isEqualTo($this->money($request->approved_amount)), 'Transfer amount must equal approval exactly.');
            if ($stage === 3) {
                $this->open($case);
                $this->check(strtolower($transfer->transfer_status) === 'pending' && strtolower($refund->refund_status) === 'approved' && ! $transfer->received_date && $transfer->received_amount === null, 'Instruction cannot replace payment evidence.');
                if (isset($data['transfer_amount'])) {
                    $this->check($this->money($data['transfer_amount'])->isEqualTo($this->money($request->approved_amount)), 'Instruction amount must equal approval.');
                }
                unset($data['transfer_amount']);
                $transfer->update($data + ['transfer_status' => 'processing']);
                $refund->update(['refund_status' => 'processed']);

                return $transfer;
            }
            $this->check($this->money($data['received_amount'])->isEqualTo($this->money($request->approved_amount)), 'A single full receipt must equal approval exactly.');
            $this->check($transfer->request_number && $transfer->request_date && $transfer->transfer_number && $transfer->transfer_date
                && $transfer->instruction_number && $transfer->instruction_issue_date && $transfer->instruction_received_date, 'Transfer request and instruction evidence are required.');
            $closed = CaseStatus::where('code', 'CLOSED')->sole();
            if (strtolower($refund->refund_status) === 'completed' || strtolower($transfer->transfer_status) === 'completed' || $case->is_completed || $case->case_status_id === $closed->id) {
                $this->check(strtolower($refund->refund_status) === 'completed' && strtolower($transfer->transfer_status) === 'completed'
                    && $case->is_completed && $case->case_status_id === $closed->id && $case->completed_date
                    && $transfer->receipt_number === $data['receipt_number'] && $transfer->received_date?->toDateString() === $data['received_date']
                    && $transfer->received_amount !== null && $this->money($transfer->received_amount)->isEqualTo($this->money($data['received_amount']))
                    && StatusHistory::where('tax_case_id', $case->id)->where('new_status_id', $closed->id)->where('reason', 'Preliminary full receipt: '.$refund->id)->exists(), 'Conflicting or unverified completion retry.');

                return $transfer;
            }
            $this->open($case);
            $this->check(strtolower($refund->refund_status) === 'processed' && strtolower($transfer->transfer_status) === 'processing'
                && $transfer->received_amount === null && ! $transfer->received_date && ! $transfer->receipt_number, 'Existing receipt or conflicting status requires separate review.');
            $transfer->update($data + ['transfer_status' => 'completed']);
            $refund->update(['refund_status' => 'completed']);
            StatusHistory::create(['tax_case_id' => $case->id, 'old_status_id' => $case->case_status_id, 'new_status_id' => $closed->id,
                'changed_by' => auth()->id(), 'reason' => 'Preliminary full receipt: '.$refund->id, 'created_at' => now()]);
            $case->forceFill(['case_status_id' => $closed->id, 'is_completed' => true, 'completed_date' => now(), 'explicitly_closed' => true])->save();

            return $transfer;
        });
    }
}
