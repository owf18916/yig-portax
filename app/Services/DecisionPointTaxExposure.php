<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use App\Models\TaxCase;
use Illuminate\Validation\ValidationException;

/**
 * Confirmed Phase 3C arithmetic. Callers must supply a proven SPT direction;
 * filing categories (SPT / Pengembalian Pendahuluan) are not directions.
 * Money enters and leaves as decimal strings. Evaluation loads fixed relations;
 * the calculator never mutates workflow or monetary records.
 */
final class DecisionPointTaxExposure
{
    public const RELATIONS = ['skpRecord', 'objectionDecision', 'appealDecision', 'supremeCourtDecision'];

    public function validatePosition(string $type, mixed $amount, string $prefix = 'spt'): void
    {
        $this->position($type, $amount, $prefix);
    }

    public function validateVatInputs(array $values): void
    {
        if (!array_key_exists('vat_in_amount', $values) && !array_key_exists('vat_out_amount', $values)) return;
        $input = $this->money((string) ($values['vat_in_amount'] ?? ''), 'vat_in_amount');
        $output = $this->money((string) ($values['vat_out_amount'] ?? ''), 'vat_out_amount');
        $position = $output->minus($input);
        $expected = $position->isZero() ? 'NIHIL' : ($position->isNegative() ? 'LB' : 'KB');
        if ($values['spt_position_type'] !== $expected || !$position->abs()->isEqualTo((string) $values['disputed_amount'])) {
            throw ValidationException::withMessages(['spt_position_type' => 'SPT position and magnitude must match the supplied VAT inputs.']);
        }
    }

    /** Fixed set of inputs loaded once; unresolved never means zero/not eligible. */
    public function evaluate(TaxCase $case): array
    {
        $case->loadMissing(self::RELATIONS);
        $results = [];
        $prior = null;
        $problem = null;
        try {
            $this->validatePosition((string) $case->spt_position_type, $case->disputed_amount);
        } catch (ValidationException $e) {
            $problem = implode(' ', array_merge(...array_values($e->errors())));
        }
        foreach ([4 => 'skpRecord', 7 => 'objectionDecision', 10 => 'appealDecision', 12 => 'supremeCourtDecision'] as $stage => $relation) {
            $record = $case->{$relation};
            $previous = $prior;
            $state = $problem ? 'unresolved' : 'pending';
            $remaining = null;
            if (!$problem && $record && ($stage === 4 || $prior !== null)) {
                try {
                    if ($stage === 7 && $record->decision_type === 'partially_granted') {
                        throw ValidationException::withMessages(['decision_amount' => 'Stage 7 records the decision document value, not recovery. Partial-decision exposure requires an owner-approved recovery contract.']);
                    }
                    // Stage 7 fixed outcomes retain existing outcome-only semantics;
                    // its document amount is never supplied as recovery.
                    $remaining = $stage === 4
                        ? $this->stageFour($case->spt_position_type, $case->disputed_amount, (string) $record->skp_type, $record->skp_amount)
                        : $this->afterDecision($prior, (string) $record->decision_type, $stage === 7 ? '0' : $record->decision_amount);
                    $state = 'resolved';
                } catch (ValidationException $e) {
                    $problem = implode(' ', array_merge(...array_values($e->errors())));
                    $state = 'unresolved';
                }
            } elseif (!$problem && $record) {
                $problem = 'Previous Decision Point exposure is unavailable.';
                $state = 'unresolved';
            }
            $results[$stage] = [
                'evaluationState' => $state,
                'evaluationReason' => $problem,
                'previousExposure' => $previous,
                'remainingExposure' => $remaining,
                'needsKian' => $state === 'resolved' ? $this->eligible($remaining) : null,
            ];
            $prior = $remaining;
        }

        return $results;
    }

    public function normalizeForCase(TaxCase $case, int $stage, array $values): array
    {
        if ($stage === 1) {
            $this->validatePosition((string) ($values['spt_position_type'] ?? $case->spt_position_type),
                (string) ($values['disputed_amount'] ?? $case->disputed_amount));
        } elseif ($stage === 4) {
            $this->validatePosition((string) ($values['skp_type'] ?? ''), (string) ($values['skp_amount'] ?? ''), 'skp');
        } elseif ($stage === 7) {
            if (!in_array($values['decision_type'] ?? null, ['granted', 'partially_granted', 'rejected'], true)) {
                throw ValidationException::withMessages(['decision_type' => 'A valid decision outcome is required.']);
            }
            $values['decision_amount'] = $this->documentAmount($values['decision_amount'] ?? null);
        } elseif (in_array($stage, [10, 12], true)) {
            $previousStage = [7 => 4, 10 => 7, 12 => 10][$stage];
            $evaluation = $this->evaluate($case)[$previousStage];
            if ($evaluation['evaluationState'] !== 'resolved') {
                throw ValidationException::withMessages(['prior_exposure' => $evaluation['evaluationReason'] ?? 'Previous Decision Point exposure is unavailable.']);
            }
            $values['decision_amount'] = $this->normalizeRecovery($evaluation['remainingExposure'],
                (string) ($values['decision_type'] ?? ''), (string) ($values['decision_amount'] ?? ''));
        }

        return $values;
    }

    /** Stage 7 document value: exact money, independent of outcome/exposure. */
    public function documentAmount(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value) && !(is_float($value) && is_finite($value)))
            || preg_match('/^\d+(?:\.\d{1,2})?$/D', (string) $value) !== 1) {
            throw ValidationException::withMessages(['decision_amount' => 'A nonnegative decision document amount with at most two decimal places is required.']);
        }

        return (string) $this->money((string) $value, 'decision_amount');
    }

    public function stageFour(string $sptType, mixed $sptAmount, string $skpType, mixed $skpAmount): string
    {
        $spt = $this->position($sptType, $sptAmount, 'spt');
        $skp = $this->position($skpType, $skpAmount, 'skp');
        $difference = $skp->minus($spt);

        return $difference->isNegative() ? '0.00' : (string) $difference;
    }

    /** Effective recovery for a NEW final submission or approved Revision. */
    public function normalizeRecovery(mixed $priorExposure, string $outcome, mixed $amount): string
    {
        $prior = $this->money($priorExposure, 'prior_exposure');
        // Still reject malformed/negative monetary input, even for fixed outcomes.
        $recovery = $this->money($amount, 'decision_amount');

        return match ($outcome) {
            'rejected' => '0.00',
            'granted' => (string) $prior,
            'partially_granted' => $recovery->isGreaterThan(0) && $recovery->isLessThan($prior)
                ? (string) $recovery
                : throw ValidationException::withMessages([
                    'decision_amount' => 'Partial recovery must be greater than zero and less than prior remaining exposure.',
                ]),
            default => throw ValidationException::withMessages([
                'decision_type' => 'This outcome has no confirmed recovery semantics.',
            ]),
        };
    }

    /**
     * Derive exposure without rewriting historical decision amounts.
     * Rejected/granted follow their confirmed economic meaning; incompatible
     * historical partial amounts fail explicitly instead of being clamped.
     */
    public function afterDecision(mixed $priorExposure, string $outcome, mixed $amount): string
    {
        $prior = $this->money($priorExposure, 'prior_exposure');
        $recovery = $this->normalizeRecovery($priorExposure, $outcome, $amount);

        return (string) $prior->minus($recovery);
    }

    public function eligible(mixed $remainingExposure): bool
    {
        return $this->money($remainingExposure, 'remaining_exposure')->isGreaterThan(0);
    }

    private function position(string $type, mixed $amount, string $prefix): BigDecimal
    {
        $money = $this->money($amount, $prefix.'_amount');
        if (!in_array($type, ['LB', 'KB', 'NIHIL'], true)) {
            throw ValidationException::withMessages([($prefix === 'spt' ? 'spt_position_type' : 'skp_type') => 'Explicit LB, KB or NIHIL direction is required.']);
        }
        if ($type === 'NIHIL' && !$money->isZero()) {
            throw ValidationException::withMessages([$prefix.'_amount' => 'NIHIL requires a zero amount.']);
        }

        return $type === 'LB' ? $money->negated() : $money;
    }

    private function money(mixed $value, string $field): BigDecimal
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^\d+(?:\.\d+)?$/D', (string) $value) !== 1) {
            throw ValidationException::withMessages([$field => 'A nonnegative decimal amount is required.']);
        }
        try {
            return BigDecimal::of($value)->toScale(2);
        } catch (MathException) {
            throw ValidationException::withMessages([$field => 'Amount must be exact to two decimal places.']);
        }
    }
}
