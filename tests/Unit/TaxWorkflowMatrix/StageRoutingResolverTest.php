<?php

namespace Tests\Unit\TaxWorkflowMatrix;

use App\Models\ObjectionDecision;
use App\Models\TaxCase;
use App\Models\WorkflowHistory;
use App\Services\TaxWorkflowMatrix\StageRoutingResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

class StageRoutingResolverTest extends TestCase
{
    public function test_stage_7_conflicting_evidence_is_not_finalized_by_current_stage(): void
    {
        $case = new TaxCase(['current_stage' => 8]);
        $case->setRelation('objectionDecision', new ObjectionDecision([
            'decision_type' => 'rejected',
            'continue_to_next_stage' => false,
            'next_stage' => 8,
        ]));
        $case->setRelation('workflowHistories', new Collection([
            tap(new WorkflowHistory(['stage_id' => 7, 'status' => 'submitted', 'stage_to' => 8]), fn ($h) => $h->id = 1),
        ]));

        $result = (new StageRoutingResolver())->resolveAfterStage7($case);

        $this->assertSame('unfinalized', $result['state']);
        $this->assertSame('conflicting_route', $result['data_quality'][0]['code']);
    }

    public function test_stage_7_missing_partial_route_is_ambiguous(): void
    {
        $case = new TaxCase();
        $case->setRelation('objectionDecision', new ObjectionDecision(['decision_type' => 'partially_granted']));
        $case->setRelation('workflowHistories', new Collection());

        $result = (new StageRoutingResolver())->resolveAfterStage7($case);

        $this->assertSame('unfinalized', $result['state']);
        $this->assertSame('ambiguous_route', $result['data_quality'][0]['code']);
    }
}
