<?php

namespace Tests\Unit\TaxWorkflowMatrix;

use App\Models\TaxCase;
use App\Models\WorkflowHistory;
use App\Services\TaxWorkflowMatrix\StageDefinitions;
use App\Services\TaxWorkflowMatrix\StageRoutingResolver;
use App\Services\TaxWorkflowMatrix\StageStatusResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

class StageStatusResolverTest extends TestCase
{
    public function test_submitted_and_legacy_final_history_complete_stages_without_current_stage(): void
    {
        $resolver = new StageStatusResolver(new StageRoutingResolver());
        $case = new TaxCase(['current_stage' => 1]);
        $case->id = 10;
        $case->setRelation('workflowHistories', new Collection([
            new WorkflowHistory(['stage_id' => 1, 'status' => 'draft']),
            tap(new WorkflowHistory(['stage_id' => 1, 'status' => 'submitted']), fn ($h) => $h->id = 2),
            tap(new WorkflowHistory(['stage_id' => 2, 'status' => 'approved']), fn ($h) => $h->id = 3),
        ]));
        foreach (StageDefinitions::STAGES as $definition) {
            if ($definition['relation']) {
                $case->setRelation($definition['relation'], null);
            }
        }

        $this->assertSame('completed', $resolver->effectiveStatus($case, 1)['status']);
        $legacy = $resolver->effectiveStatus($case, 2);
        $this->assertSame('completed', $legacy['status']);
        $this->assertSame('legacy_status_value', $legacy['data_quality'][0]['code']);
    }
}
