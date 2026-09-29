<?php

namespace Tests\Unit;

use App\Models\AppealDecision;
use App\Models\CaseStatus;
use App\Models\ObjectionDecision;
use App\Models\SkpRecord;
use App\Models\TaxCase;
use App\Models\WorkflowHistory;
use App\Services\MainWorkflowStateResolver;
use App\Services\MainWorkflowTransitionGuard;
use App\Services\TaxWorkflowMatrix\StageDefinitions;
use App\Services\TaxWorkflowMatrix\StageRoutingResolver;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MainWorkflowStateResolverTest extends TestCase
{
    private MainWorkflowStateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MainWorkflowStateResolver(new StageRoutingResolver);
    }

    public function test_sequential_state_is_derived_from_evidence_not_current_stage(): void
    {
        $state = $this->resolver->resolve($this->taxCase([1, 2, 3], currentStage: 12));

        $this->assertSame([1, 2, 3], $state['completed_stages']);
        $this->assertSame([4], $state['available_stages']);
        $this->assertSame(4, $state['current_stage']);
        $this->assertFalse($state['terminal']);
        $this->assertSame('locked', $state['stages'][4]['status']);
    }

    public static function stageFourChoices(): array
    {
        return [
            'neither' => [false, false, false],
            'refund only' => [true, false, false],
            'continue only' => [false, true, true],
            'refund and continue' => [true, true, true],
        ];
    }

    #[DataProvider('stageFourChoices')]
    public function test_stage_five_depends_only_on_continue(bool $refund, bool $continue, bool $available): void
    {
        $case = $this->taxCase([1, 2, 3, 4]);
        $case->setRelation('skpRecord', new SkpRecord([
            'create_refund' => $refund,
            'continue_to_next_stage' => $continue,
        ]));

        $state = $this->resolver->resolve($case);

        $this->assertSame($available, $state['stages'][4]['submittable']);
    }

    public static function laterDecisionPoints(): array
    {
        return [
            'stage 7 stops' => [7, 8, false],
            'stage 7 continues' => [7, 8, true],
            'stage 10 stops' => [10, 11, false],
            'stage 10 continues' => [10, 11, true],
        ];
    }

    #[DataProvider('laterDecisionPoints')]
    public function test_later_decision_points_depend_on_continue(int $decisionStage, int $nextStage, bool $continue): void
    {
        $case = $this->taxCase(range(1, $decisionStage), decisionFlags: [4 => true, $decisionStage => $continue]);
        $state = $this->resolver->resolve($case);

        $this->assertSame($continue, $state['stages'][$nextStage - 1]['submittable']);
    }

    public function test_stage_twelve_is_terminal_and_never_produces_a_pseudo_stage(): void
    {
        $state = $this->resolver->resolve($this->taxCase(range(1, 12), decisionFlags: [4 => true, 7 => true, 10 => true]));

        $this->assertTrue($state['terminal']);
        $this->assertSame(12, $state['current_stage']);
        $this->assertSame([], $state['available_stages']);
        $this->assertSame(range(1, 12), array_column($state['stages'], 'stage'));
    }

    public function test_guard_and_resolver_share_submittability_for_every_stage(): void
    {
        $guard = new MainWorkflowTransitionGuard($this->resolver);

        foreach (range(1, 12) as $stage) {
            $case = $this->taxCase(
                $stage === 1 ? [] : range(1, $stage - 1),
                decisionFlags: [4 => true, 7 => true, 10 => true]
            );
            $state = $this->resolver->resolve($case);
            $resolved = collect($state['stages'])->firstWhere('stage', $stage);

            $this->assertTrue($resolved['submittable'], "Resolver should allow stage {$stage}");
            $this->assertTrue($guard->inspect($case, $stage)['allowed'], "Guard should allow stage {$stage}");

            $case = $this->taxCase(range(1, $stage), decisionFlags: [4 => true, 7 => true, 10 => true]);
            $this->assertFalse($guard->inspect($case, $stage)['allowed'], "Guard should reject completed stage {$stage}");
        }
    }

    public function test_closed_and_inconsistent_cases_are_conservatively_locked(): void
    {
        $closed = $this->taxCase([1], isCompleted: true);
        $closedState = $this->resolver->resolve($closed);
        $this->assertTrue($closedState['terminal']);
        $this->assertSame([], $closedState['available_stages']);

        $inconsistent = $this->resolver->resolve($this->taxCase([1, 3], currentStage: 4));
        $this->assertSame('inconsistent_persisted_state', $inconsistent['stages'][1]['reason']);
        $this->assertSame([], $inconsistent['available_stages']);
    }

    public function test_conflicting_record_and_history_finality_is_diagnostic_and_locked(): void
    {
        $case = $this->taxCase([1, 2, 3]);
        $draft = new WorkflowHistory(['stage_id' => 4, 'status' => 'draft']);
        $draft->id = 4;
        $case->workflowHistories->push($draft);
        $record = new SkpRecord(['continue_to_next_stage' => true]);
        $record->setAttribute('status', 'submitted');
        $case->setRelation('skpRecord', $record);

        $state = $this->resolver->resolve($case);

        $this->assertSame([], $state['available_stages']);
        $this->assertSame('inconsistent_persisted_state', $state['stages'][3]['reason']);
        $this->assertTrue(collect($state['diagnostics'])->contains(
            fn (array $diagnostic) => $diagnostic['code'] === 'final_record_conflicts_with_draft_history'
        ));
    }

    private function taxCase(
        array $completed,
        int $currentStage = 1,
        array $decisionFlags = [],
        bool $isCompleted = false
    ): TaxCase {
        $case = new TaxCase(['current_stage' => $currentStage, 'is_completed' => $isCompleted]);
        $case->id = 99;
        $case->setRelation('status', new CaseStatus(['code' => 'OPEN']));
        $case->setRelation('workflowHistories', new Collection(array_map(
            function (int $stage) use ($decisionFlags) {
                $history = new WorkflowHistory([
                    'stage_id' => $stage,
                    'status' => 'submitted',
                    'stage_to' => ($decisionFlags[$stage] ?? false) ? $stage + 1 : null,
                ]);
                $history->id = $stage;

                return $history;
            },
            $completed
        )));

        foreach (StageDefinitions::STAGES as $definition) {
            if ($definition['relation']) {
                $case->setRelation($definition['relation'], null);
            }
        }
        if (array_key_exists(4, $decisionFlags)) {
            $case->setRelation('skpRecord', new SkpRecord(['continue_to_next_stage' => $decisionFlags[4]]));
        }
        if (array_key_exists(7, $decisionFlags)) {
            $case->setRelation('objectionDecision', new ObjectionDecision(['continue_to_next_stage' => $decisionFlags[7]]));
        }
        if (array_key_exists(10, $decisionFlags)) {
            $case->setRelation('appealDecision', new AppealDecision(['continue_to_next_stage' => $decisionFlags[10]]));
        }

        return $case;
    }
}
