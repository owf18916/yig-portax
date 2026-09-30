<?php

namespace App\Services;

use App\Models\TaxCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RevisionMutationGuard
{
    public function assertStageIsEditable(TaxCase $case, int $stage): void
    {
        $submitted = $case->workflowHistories()->where('stage_id', $stage)
            ->whereIn('status', ['submitted', 'approved', 'completed'])->exists();
        if ($submitted) throw new ConflictHttpException("Stage {$stage} is already submitted; business-field changes must use the Revision workflow.");
    }
}
