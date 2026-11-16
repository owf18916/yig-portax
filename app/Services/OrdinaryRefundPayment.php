<?php

namespace App\Services;

use App\Models\{BankTransferRequest, RefundProcess, TaxCase, SkpRecord, ObjectionDecision, AppealDecision, SupremeCourtDecision};
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;

class OrdinaryRefundPayment
{
    private function check(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['ordinary_refund' => $message]);
        }
    }

    private function money(mixed $value): BigDecimal
    {
        $this->check((is_string($value) || is_int($value)) && preg_match('/^\d{1,18}(?:\.\d{1,2})?$/D', (string) $value), 'Use an exact positive decimal with at most two decimal places.');
        $amount = BigDecimal::of($value)->toScale(2);
        $this->check($amount->isGreaterThan(0), 'Payment amount must be positive.');

        return $amount;
    }

    // Advisory availability for a NEW submission, never for an exact retry.
    // The writer repeats these checks against locked records before mutation.
    public function canSubmit(TaxCase $case, RefundProcess $refund, int $stage): bool
    {
        if (! in_array($stage, [2, 3, 4], true)
            || (int) $refund->tax_case_id !== (int) $case->id
            || ! auth()->user()?->is_active
            || ! auth()->user()->hasPermission('submit_cases')
            || ! Gate::allows('update', $case)) {
            return false;
        }

        try {
            $approved = $this->approvedAmount($case, $refund);
            $transfers = BankTransferRequest::withTrashed()->where('refund_process_id', $refund->id)->orderBy('id')->limit(2)->get();
            $this->check($transfers->count() <= 1, 'Multiple historical transfers require owner review; payment is blocked.');
            $transfer = $transfers->first();
            if ($transfer) {
                $this->recordedStage($refund, $transfer, $approved);
            }
            $this->checkNewSubmission($refund, $transfer, $stage);

            return true;
        } catch (ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return false;
        }
    }

    private function approvedAmount(TaxCase $case, RefundProcess $refund, bool $payment = true): BigDecimal
    {
        $this->check(! $payment || (int) $refund->stage_id !== 4 || ! $case->stageFourKianAccessBlocked(), 'Submit the required Stage 4 KIAN before continuing this Refund.');
        $origins = [4 => SkpRecord::class, 7 => ObjectionDecision::class, 10 => AppealDecision::class, 12 => SupremeCourtDecision::class];
        $sources = [4 => 'SKP', 7 => 'OBJECTION', 10 => 'APPEAL', 12 => 'SUPREME_COURT'];
        $type = $origins[$refund->stage_id] ?? null;
        $this->check($type !== null && ! PreliminaryRefundLifecycle::concerns($case, $refund), 'Select an ordinary decision-stage Refund.');
        $this->check($refund->triggered_by_decision_type === $type && $refund->triggered_by_decision_id && $refund->stage_source === $sources[$refund->stage_id], 'Unverified decision ownership requires separate review.');
        $siblings = RefundProcess::withTrashed()->where(function ($q) use ($case, $refund) {
            $q->where(fn ($q) => $q->where('tax_case_id', $case->id)->where('stage_id', $refund->stage_id))
                ->orWhere(fn ($q) => $q->where('triggered_by_decision_type', $refund->triggered_by_decision_type)->where('triggered_by_decision_id', $refund->triggered_by_decision_id));
        })->orderBy('id')->limit(2)->get();
        $this->check($siblings->count() === 1 && !$siblings->first()->trashed() && (int)$siblings->first()->id === (int)$refund->id, 'Duplicate or deleted Refund evidence requires separate review.');
        $decision = $type::where('tax_case_id', $case->id)->whereKey($refund->triggered_by_decision_id)->firstOrFail();
        $this->check((bool) $decision->create_refund && $case->workflowHistories()->where('stage_id', $refund->stage_id)->whereIn('status', ['submitted', 'approved', 'completed'])->exists(), 'Submit the originating Refund decision first.');
        $approved = $this->money($refund->refund_amount);
        $this->check($approved->isEqualTo($this->money($decision->refund_amount)) && $refund->refund_method === 'bank_transfer', 'Refund amount or method conflicts with its originating decision.');

        return $approved;
    }

    private function recordedStage(RefundProcess $refund, BankTransferRequest $transfer, BigDecimal $approved, ?BigDecimal $historical = null): int
    {
        $historical ??= $this->historicalAmount($refund, $transfer, $approved);
        $this->check($transfer->request_number && $transfer->request_date && $transfer->transfer_date, 'Recorded transfer request evidence is required.');
        $recordedStage = match ([$refund->refund_status, $transfer->transfer_status]) {
            ['approved', 'pending'] => 2,
            ['processed', 'processing'] => 3,
            ['completed', 'completed'] => 4,
            default => 0,
        };
        if ($recordedStage >= 3) {
            $this->check($transfer->instruction_number && $transfer->instruction_issue_date && $transfer->instruction_received_date, 'Recorded instruction evidence is incomplete.');
        }
        if ($recordedStage === 4) {
            $this->check($transfer->receipt_number && $transfer->received_date && $historical->isEqualTo($this->money($transfer->received_amount)), 'Recorded full receipt evidence is incomplete.');
        } else {
            $this->check(! $transfer->receipt_number && ! $transfer->received_date && $transfer->received_amount === null, 'Existing receipt evidence requires separate review.');
        }

        $this->check($recordedStage > 0, 'Payment lifecycle conflicts with recorded evidence.');
        if ($recordedStage === 2) $this->check(!$transfer->instruction_number && !$transfer->instruction_issue_date && !$transfer->instruction_received_date, 'Unexpected instruction evidence requires separate review.');
        return $recordedStage;
    }

    private function historicalAmount(RefundProcess $refund, BankTransferRequest $transfer, BigDecimal $current): BigDecimal
    {
        $this->check(!$transfer->trashed(), 'Deleted payment evidence requires separate review.');
        $historical = $this->money($transfer->transfer_amount);
        // Only a canonical approved documentary change explains a divergence.
        // Latest authority audit is selected in one bounded query; notes-only audits are excluded.
        $revision = \App\Models\Revision::where('target_type', $refund->triggered_by_decision_type)
            ->where('target_id', $refund->triggered_by_decision_id)->where('stage_code', $refund->stage_id)
            ->where('revisable_id', $refund->tax_case_id)->where('revision_status', 'approved')
            ->whereIn('revisable_type', [TaxCase::class, 'TaxCase'])
            ->whereNotNull('audit_data->refund_authority->refund_process_id')->orderByDesc('approved_at')->orderByDesc('id')->first();
        $authority = $revision?->audit_data['refund_authority'] ?? [];
        if ($revision) {
            $this->check((int)($authority['refund_process_id'] ?? 0) === (int)$refund->id
                && ($authority['after'] ?? null) === (string)$current, 'Current Refund conflicts with the last approved documentary authority.');
        }
        if ($authority['transfer_id'] ?? null) {
            $this->check((int)$authority['transfer_id'] === (int)$transfer->id
                && ($authority['historical_amount'] ?? null) === (string)$historical, 'Historical payment conflicts with its approved documentary Revision evidence.');
        } else {
            $this->check($historical->isEqualTo($current), 'Unexplained historical payment mismatch requires separate review.');
        }
        return $historical;
    }

    public function assertRevisionAuthority(TaxCase $case, RefundProcess $refund): ?BankTransferRequest
    {
        $current = $this->approvedAmount($case, $refund, false);
        $transfers = BankTransferRequest::withTrashed()->where('refund_process_id', $refund->id)->orderBy('id')->limit(2)->lockForUpdate()->get();
        $this->check($transfers->count() <= 1, 'Multiple historical transfers require separate review.');
        if ($transfers->first()) $this->recordedStage($refund, $transfers->first(), $current);
        else $this->check($refund->refund_status === 'pending' && $refund->status === 'draft', 'Initial Refund state is inconsistent.');
        return $transfers->first();
    }

    private function checkNewSubmission(RefundProcess $refund, ?BankTransferRequest $transfer, int $stage): void
    {
        if (! $transfer) {
            $this->check($stage === 2 && $refund->refund_status === 'pending', 'Complete the predecessor payment stage first.');

            return;
        }
        $predecessor = [3 => ['approved', 'pending'], 4 => ['processed', 'processing']][$stage] ?? null;
        $this->check($predecessor && $refund->refund_status === $predecessor[0] && $transfer->transfer_status === $predecessor[1], 'Payment stage is locked or out of order.');
        $this->check(! $transfer->receipt_number && ! $transfer->received_date && $transfer->received_amount === null, 'Existing receipt evidence requires separate review.');
        if ($stage === 3) {
            $this->check(! $transfer->instruction_number && ! $transfer->instruction_issue_date && ! $transfer->instruction_received_date, 'Existing instruction evidence cannot be replaced.');
        } else {
            $this->check($transfer->instruction_number && $transfer->instruction_issue_date && $transfer->instruction_received_date, 'Complete instruction evidence is required before receipt.');
        }
    }

    public function payment(int $caseId, mixed $refundId, int $stage, array $input): BankTransferRequest
    {
        validator(['refund_process_id' => $refundId], ['refund_process_id' => 'required|integer|min:1'])->validate();
        $this->check(in_array($stage, [2, 3, 4], true), 'Unsupported ordinary payment stage.');

        return DB::transaction(function () use ($caseId, $refundId, $stage, $input) {
            // All canonical operations serialize on case, then Refund, then transfer.
            $case = TaxCase::whereKey($caseId)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $case);
            abort_unless(auth()->user()?->is_active && auth()->user()->hasPermission('submit_cases'), 403);
            $refund = RefundProcess::where('tax_case_id', $caseId)->whereKey($refundId)->lockForUpdate()->firstOrFail();
            $approved = $this->approvedAmount($case, $refund);

            $rules = match ($stage) {
                2 => ['request_number' => 'required|string|max:255', 'request_date' => 'required|date_format:Y-m-d', 'transfer_date' => 'required|date_format:Y-m-d'],
                3 => ['instruction_number' => 'required|string|max:255', 'instruction_issue_date' => 'required|date_format:Y-m-d', 'instruction_received_date' => 'required|date_format:Y-m-d', 'bank_code' => 'nullable|string|max:255', 'bank_name' => 'nullable|string|max:255', 'account_number' => 'nullable|string|max:255', 'account_holder' => 'nullable|string|max:255', 'account_name' => 'nullable|string|max:255', 'transfer_amount' => 'nullable'],
                4 => ['receipt_number' => 'required|string|max:255', 'received_date' => 'required|date_format:Y-m-d', 'received_amount' => 'required'],
            };
            $allowed = array_merge(array_keys($rules), ['notes', 'refund_process_id', 'bank_transfer_request_id']);
            $this->check(! array_diff(array_keys($input), $allowed), 'Unsupported payment fields; ownership, status and currency cannot be overridden.');
            $data = validator($input, $rules + ['notes' => 'nullable|string', 'refund_process_id' => 'sometimes|integer|min:1', 'bank_transfer_request_id' => 'sometimes|integer|min:1'])->validate();
            if (isset($input['refund_process_id'])) {
                abort_unless((string) $input['refund_process_id'] === (string) $refund->id, 404);
            }
            unset($data['refund_process_id'], $data['bank_transfer_request_id']);
            $transfers = BankTransferRequest::withTrashed()->where('refund_process_id', $refund->id)->orderBy('id')->limit(2)->lockForUpdate()->get();
            $this->check($transfers->count() <= 1, 'Multiple historical transfers require owner review; payment is blocked.');
            $transfer = $transfers->first();
            if ($transfer) $approved = $this->historicalAmount($refund, $transfer, $approved);
            if (isset($data['transfer_amount'])) {
                $this->check($approved->isEqualTo($this->money($data['transfer_amount'])), 'Instruction must equal the approved Refund amount exactly.');
            }
            if ($stage === 4) {
                $this->check($approved->isEqualTo($this->money($data['received_amount'])), 'A single full receipt must equal the authorized transfer amount exactly; partial and excess payments are rejected.');
                $data['received_amount'] = (string) $approved;
            }
            unset($data['transfer_amount']);

            if (isset($input['bank_transfer_request_id'])) {
                abort_unless($transfer && (string) $input['bank_transfer_request_id'] === (string) $transfer->id, 404);
            }
            if (! $transfer) {
                $this->checkNewSubmission($refund, null, $stage);
                $transfer = BankTransferRequest::create($data + ['refund_process_id' => $refund->id, 'transfer_number' => 'TRN-ORD-'.$refund->id, 'transfer_amount' => (string) $approved, 'transfer_status' => 'pending', 'created_by' => auth()->id()]);
                $transfer->refresh();
                $this->check($approved->isEqualTo($this->money($transfer->transfer_amount)), 'Transfer amount could not be stored exactly.');
                $refund->update(['refund_status' => 'approved']);

                return $transfer;
            }
            $recordedStage = $this->recordedStage($refund, $transfer, $this->money($refund->refund_amount), $approved);
            // One notes column cannot preserve separate stage evidence. Never overwrite it.
            $this->check(! isset($data['notes']) || $data['notes'] === $transfer->notes, 'Recorded notes are immutable; separate correction policy is required.');
            unset($data['notes']);
            $target = [2 => ['approved', 'pending'], 3 => ['processed', 'processing'], 4 => ['completed', 'completed']][$stage];
            if ($recordedStage >= $stage) {
                foreach ($data as $key => $value) {
                    $stored = $transfer->getAttribute($key);
                    if ($stored instanceof \DateTimeInterface) {
                        $stored = $stored->format('Y-m-d');
                    }
                    $this->check($stored === $value, 'Conflicting retry; recorded payment evidence cannot be replaced.');
                }

                return $transfer;
            }
            $this->checkNewSubmission($refund, $transfer, $stage);
            $transfer->update($data + ['transfer_status' => $target[1]]);
            $refund->update(['refund_status' => $target[0]]);

            // Ordinary settlement never changes TaxCase or its main workflow.
            return $transfer;
        });
    }
}
