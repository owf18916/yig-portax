<?php

namespace App\Services;

use App\Models\RefundProcess;
use Illuminate\Database\Eloquent\Model;

class DecisionPointRefundService
{
    /** Create the one parallel refund process belonging to a decision stage. */
    public function createIfRequested(Model $decision, int $stageId, int $userId): ?RefundProcess
    {
        if (! (bool) $decision->getAttribute('create_refund')) {
            return null;
        }

        $source = match ($stageId) {
            4 => RefundProcess::STAGE_SOURCE_SKP,
            7 => RefundProcess::STAGE_SOURCE_OBJECTION,
            10 => RefundProcess::STAGE_SOURCE_APPEAL,
            12 => RefundProcess::STAGE_SOURCE_SUPREME_COURT,
        };

        return RefundProcess::firstOrCreate(
            ['tax_case_id' => $decision->tax_case_id, 'stage_id' => $stageId],
            [
                'refund_number' => sprintf('DECISION-%d-%d-%d', $stageId, $decision->tax_case_id, $decision->id),
                'refund_amount' => $decision->getAttribute('refund_amount') ?: 0,
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
    }
}
