<?php

namespace Tests\Unit\TaxWorkflowMatrix;

use App\Models\RefundProcess;
use App\Models\TaxCase;
use App\Services\TaxWorkflowMatrix\RefundAggregateResolver;
use App\Services\TaxWorkflowMatrix\StageRoutingResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RefundAggregateResolverTest extends TestCase
{
    public function test_no_refunds_are_unavailable_without_explicit_intent(): void
    {
        $case = new TaxCase();
        $case->id = 50;
        foreach (['skpRecord', 'objectionDecision', 'appealDecision', 'supremeCourtDecision'] as $relation) {
            $case->setRelation($relation, null);
        }
        $case->setRelation('refundProcesses', new Collection());

        $result = (new RefundAggregateResolver(new StageRoutingResolver()))->resolve($case);

        $this->assertSame('not_available', $result['status']);
        $this->assertSame(0, $result['count']);
    }

    public function test_one_incomplete_refund_prevents_completed_aggregate(): void
    {
        $case = new TaxCase();
        $case->id = 51;
        $completed = new RefundProcess(['stage_id' => 4, 'refund_status' => 'completed']);
        $completed->id = 1;
        $completed->setRelation('bankTransferRequests', new Collection());
        $active = new RefundProcess(['stage_id' => 7, 'refund_status' => 'pending']);
        $active->id = 2;
        $active->setRelation('bankTransferRequests', new Collection());
        $case->setRelation('refundProcesses', new Collection([$completed, $active]));

        $result = (new RefundAggregateResolver(new StageRoutingResolver()))->resolve($case);

        $this->assertSame('input_required', $result['status']);
        $this->assertSame(2, $result['count']);
        $this->assertSame('TaxCaseDetail', $result['action']['name']);
    }
}
