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
    public static function amendedRouteEvidence(): array
    {
        $rows = [];
        foreach ([4, 7, 10] as $stage) foreach (['valid', 'requested', 'unrelated', 'wrong-target', 'mismatched-current', 'newer-submission'] as $shape) $rows[] = [$stage, $shape];
        return $rows;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('amendedRouteEvidence')]
    public function test_only_applied_exact_target_route_amendment_supersedes_history(int $stage, string $shape): void
    {
        $class = [4=>\App\Models\SkpRecord::class, 7=>ObjectionDecision::class, 10=>\App\Models\AppealDecision::class][$stage];
        $record = new $class(['continue_to_next_stage'=>false]); $record->id=5; $record->exists=true;
        $record->next_stage = $shape === 'mismatched-current' ? $stage+1 : null;
        $case = new TaxCase(); $case->id=99;
        $case->setRelation([4=>'skpRecord',7=>'objectionDecision',10=>'appealDecision'][$stage], $record);
        $history = new WorkflowHistory(['stage_id'=>$stage,'stage_to'=>$stage+1,'status'=>'submitted']);
        $history->created_at = $shape === 'newer-submission' ? '2026-11-16 12:00:00' : '2026-11-15 12:00:00';
        $case->setRelation('workflowHistories', collect([$history]));
        $revision = new \App\Models\Revision(['stage_code'=>$stage,'revision_status'=>$shape==='requested' ? 'requested' : 'approved',
            'target_type'=>$class,'target_id'=>$shape==='wrong-target' ? 6 : 5,'approved_at'=>'2026-11-16 11:00:00','approved_by'=>1,
            'proposed_values'=>['continue_to_next_stage'=>false], 'revised_data'=>['continue_to_next_stage'=>false],
            'audit_data'=>['target_type'=>$class,'target_id'=>5,'downstream_check'=>['result'=>'clear'],
                'routing_impact'=>['changed'=>$shape!=='unrelated','next_stage'=>null]]]);
        $case->setRelation('approvedRoutingRevisions', collect([$revision]));
        $route = (new StageRoutingResolver())->{'resolveAfterStage'.$stage}($case);
        $this->assertSame($shape==='valid' ? 'excluded' : 'unfinalized', $route['state']);
    }

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
