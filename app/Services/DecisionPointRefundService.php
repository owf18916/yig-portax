<?php

namespace App\Services;

use App\Models\RefundProcess;
use App\Models\TaxCase;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;

class DecisionPointRefundService
{
    /** Exact input boundary shared by generic drafts/finals and dedicated final writers.
     * JSON integers are exact; JSON floats must be sent as decimal strings instead.
     */
    public function normalizeInput(array $values, bool $draft): array
    {
        validator($values, ['create_refund' => 'sometimes|boolean'])->validate();
        $required = ! $draft && (bool) ($values['create_refund'] ?? false);
        $values['refund_amount'] = $this->amount($values['refund_amount'] ?? null, $required);

        return $values;
    }

    public function amount(mixed $value, bool $required): ?string
    {
        if ($value === null || $value === '') {
            if (! $required) return null;
        } elseif ((is_string($value) || is_int($value))
            && preg_match('/^\d{1,18}(?:\.\d{1,2})?$/D', (string) $value) === 1) {
            $amount = BigDecimal::of($value)->toScale(2);
            if (! $required || $amount->isGreaterThan(0)) return (string) $amount;
        }
        throw ValidationException::withMessages([
            'refund_amount' => 'Enter an explicit positive Refund Amount as a decimal string (up to 18 integral digits and two decimal places). Drafts may leave it blank or enter zero. JSON fractional amounts must be strings.',
        ]);
    }

    /** Refuse storage precision loss, including on drafts (SQLite NUMERIC affinity). */
    public function assertStoredAmount(Model $decision, mixed $expected): Model
    {
        $persisted = $decision::where('tax_case_id', $decision->tax_case_id)->whereKey($decision->id)->firstOrFail();
        if ($this->amount($persisted->refund_amount, false) !== $this->amount($expected, false)) {
            throw ValidationException::withMessages(['refund_amount' => 'Refund Amount could not be stored exactly. No changes were saved.']);
        }

        return $persisted;
    }

    /** Create the one parallel refund process belonging to a decision stage. */
    public function createIfRequested(Model $decision, int $stageId, int $userId): ?RefundProcess
    {
        return DB::transaction(function () use ($decision, $stageId, $userId) {
            // Same serialization order as workflow/payment writers: case, then Refund.
            TaxCase::whereKey($decision->tax_case_id)->lockForUpdate()->firstOrFail();
            $types = [4 => \App\Models\SkpRecord::class, 7 => \App\Models\ObjectionDecision::class,
                10 => \App\Models\AppealDecision::class, 12 => \App\Models\SupremeCourtDecision::class];
            if (! $decision->exists || ($types[$stageId] ?? null) !== $decision::class) {
                throw ValidationException::withMessages(['refund_amount' => 'Refund origin is invalid.']);
            }
            $persisted = $this->assertStoredAmount($decision, $decision->getAttribute('refund_amount'));
            if ((bool) $persisted->create_refund !== (bool) $decision->create_refund) {
                throw ValidationException::withMessages(['refund_amount' => 'The Refund decision changed. Reload before submitting.']);
            }
            if (! $persisted->create_refund) return null;
            $amount = $this->amount($decision->getAttribute('refund_amount'), true);
            $source = match ($stageId) {
                4 => RefundProcess::STAGE_SOURCE_SKP,
                7 => RefundProcess::STAGE_SOURCE_OBJECTION,
                10 => RefundProcess::STAGE_SOURCE_APPEAL,
                12 => RefundProcess::STAGE_SOURCE_SUPREME_COURT,
            };

            $historical = RefundProcess::withTrashed()->where('tax_case_id', $decision->tax_case_id)
                ->where('stage_id', $stageId)->orderBy('id')->limit(2)->lockForUpdate()->get();
            if ($historical->count() > 1 || $historical->first()?->trashed()) {
                throw ValidationException::withMessages(['refund_amount' => 'Historical or duplicate Refund evidence requires separate owner review.']);
            }
            $refund = RefundProcess::firstOrCreate(
                ['tax_case_id' => $decision->tax_case_id, 'stage_id' => $stageId],
                [
                    'refund_number' => sprintf('DECISION-%d-%d-%d', $stageId, $decision->tax_case_id, $decision->id),
                    'refund_amount' => $amount,
                    'refund_method' => 'bank_transfer',
                    'refund_status' => 'pending',
                    'stage_source' => $source,
                    'sequence_number' => RefundProcess::getNextSequenceNumber($decision->tax_case_id),
                    'triggered_by_decision_id' => $decision->id,
                    'triggered_by_decision_type' => $decision::class,
                    'submitted_by' => $userId,
                    'submitted_at' => now(),
                    'status' => 'draft',
                ]
            );
            $refund->refresh(); // Compare stored authority, not only the insert attributes.
            if ($refund->triggered_by_decision_type !== $decision::class
                || (int) $refund->triggered_by_decision_id !== (int) $decision->id
                || $refund->stage_source !== $source || $refund->refund_method !== 'bank_transfer'
                || $this->amount($refund->refund_amount, true) !== $amount) {
                throw ValidationException::withMessages(['refund_amount' => 'An existing Refund conflicts with this decision. No amount or origin was changed; separate owner review is required.']);
            }

            return $refund;
        });
    }
}
