<?php

namespace Tests\Unit;

use App\Services\DecisionPointTaxExposure;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DecisionPointTaxExposureTest extends TestCase
{
    public static function matrix(): array
    {
        return [
            ['LB', '1000', 'LB', '750', '250.00'],
            ['LB', '1000', 'NIHIL', '0', '1000.00'],
            ['LB', '1000', 'KB', '300', '1300.00'],
            ['KB', '800', 'KB', '1000', '200.00'],
            ['KB', '1000', 'KB', '700', '0.00'],
            ['KB', '800', 'NIHIL', '0', '0.00'],
            ['KB', '800', 'LB', '200', '0.00'],
            ['NIHIL', '0', 'KB', '600', '600.00'],
            ['NIHIL', '0', 'NIHIL', '0', '0.00'],
            ['NIHIL', '0', 'LB', '300', '0.00'],
        ];
    }

    #[DataProvider('matrix')]
    public function test_confirmed_stage_four_matrix(string $spt, string $amount, string $skp, string $assessment, string $expected): void
    {
        $calculator = new DecisionPointTaxExposure;
        $remaining = $calculator->stageFour($spt, $amount, $skp, $assessment);
        self::assertSame($expected, $remaining);
        self::assertSame($expected !== '0.00', $calculator->eligible($remaining));
    }

    public function test_lb_and_kb_chains_use_previous_remaining_exposure(): void
    {
        $calculator = new DecisionPointTaxExposure;
        foreach ([['LB', '1000', 'LB', '750', '100'], ['KB', '800', 'KB', '1000', '50']] as [$spt, $amount, $skp, $assessment, $partial]) {
            $stage4 = $calculator->stageFour($spt, $amount, $skp, $assessment);
            $stage7 = $calculator->afterDecision($stage4, 'partially_granted', $partial);
            self::assertSame('150.00', $stage7);
            $stage10 = $calculator->afterDecision($stage7, 'rejected', '0');
            self::assertSame('150.00', $stage10);
            self::assertSame('0.00', $calculator->afterDecision($stage10, 'granted', '150'));
        }
    }

    public function test_appeal_examples_and_fixed_recoveries(): void
    {
        $calculator = new DecisionPointTaxExposure;
        self::assertSame('0.00', $calculator->afterDecision('1000', 'granted', '1000'));
        self::assertSame('700.00', $calculator->afterDecision('1000', 'partially_granted', '300'));
        self::assertSame('1000.00', $calculator->afterDecision('1000', 'rejected', '0'));
        self::assertSame('0.00', $calculator->normalizeRecovery('1000', 'rejected', '2200'));
        self::assertSame('1000.00', $calculator->normalizeRecovery('1000', 'granted', '20'));
    }

    public static function invalidPartialAmounts(): array
    {
        return [['0'], ['1000'], ['1000.01'], ['-0.01'], ['0.001']];
    }

    #[DataProvider('invalidPartialAmounts')]
    public function test_invalid_partial_is_rejected(string $amount): void
    {
        $this->expectException(ValidationException::class);
        (new DecisionPointTaxExposure)->normalizeRecovery('1000', 'partially_granted', $amount);
    }

    public function test_decimal_precision_and_minimum_positive_boundary(): void
    {
        $calculator = new DecisionPointTaxExposure;
        self::assertSame('0.01', $calculator->stageFour('KB', '9999999999999999.98', 'KB', '9999999999999999.99'));
        self::assertSame('0.20', $calculator->afterDecision('0.30', 'partially_granted', '0.10'));
        self::assertTrue($calculator->eligible('0.01'));
        self::assertFalse($calculator->eligible('0.00'));
    }

    public function test_zero_prior_accepts_fixed_outcomes_with_zero_recovery(): void
    {
        $calculator = new DecisionPointTaxExposure;
        self::assertSame('0.00', $calculator->afterDecision('0', 'granted', '0'));
        self::assertSame('0.00', $calculator->afterDecision('0', 'rejected', '0'));
        $this->expectException(ValidationException::class);
        $calculator->normalizeRecovery('0', 'partially_granted', '0');
    }

    public function test_unconfirmed_skp_kb_cannot_use_a_default_formula(): void
    {
        $this->expectException(ValidationException::class);
        (new DecisionPointTaxExposure)->afterDecision('1000', 'skp_kb', '300');
    }

    public static function invalidPositions(): array
    {
        return [
            ['SPT', '1000', 'LB', '750'],
            ['', '1000', 'LB', '750'],
            ['Pengembalian Pendahuluan', '1000', 'LB', '750'],
            ['LB', '-1000', 'LB', '750'],
            ['NIHIL', '1', 'KB', '600'],
            ['LB', '1000', 'NIHIL', '1'],
            ['LB', '1000', 'unknown', '1'],
            ['LB', null, 'LB', '750'],
            ['LB', '1,000', 'LB', '750'],
            ['LB', 0.1, 'LB', '0'],
        ];
    }

    #[DataProvider('invalidPositions')]
    public function test_missing_direction_and_invalid_money_are_not_guessed(string $spt, mixed $amount, string $skp, mixed $assessment): void
    {
        $this->expectException(ValidationException::class);
        (new DecisionPointTaxExposure)->stageFour($spt, $amount, $skp, $assessment);
    }
}
