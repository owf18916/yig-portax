<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\SkpRecord;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SkpRecordController extends ApiController
{
    /**
     * Store SKP record (Stage 4)
     * DECISION POINT: SKP Type determines next stage
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Validate stage
        if ($taxCase->current_stage !== 4) {
            return $this->error('Tax case is not at SKP stage', 422);
        }

        $validated = $request->validate([
            'skp_number' => 'required|string|unique:skp_records',
            'issue_date' => 'required|date',
            'receipt_date' => 'nullable|date',
            'skp_type' => 'required|in:LB,NIHIL,KB',
            'skp_amount' => 'required|numeric|min:0',
            'assessment_details' => 'nullable|json',
            'notes' => 'nullable|string',
        ]);

        $validated['tax_case_id'] = $taxCase->id;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();
        $validated['status'] = 'submitted';

        // Determine next stage based on SKP type
        $nextStage = $this->determineNextStageFromSkpType($validated['skp_type']);
        $validated['next_stage'] = $nextStage;

        $skpRecord = SkpRecord::create($validated);

        // Log workflow with decision
        $taxCase->workflowHistories()->create([
            'stage_from' => 4,
            'stage_to' => 4,
            'action' => 'submitted',
            'decision_point' => 'skp_type',
            'decision_value' => $validated['skp_type'],
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        return $this->success(
            $skpRecord->load(['taxCase', 'submittedBy']),
            'SKP record submitted successfully',
            201
        );
    }

    /**
     * Approve SKP record and route to next stage
     */
    public function approve(Request $request, TaxCase $taxCase, SkpRecord $skpRecord): JsonResponse
    {
        if ($skpRecord->tax_case_id !== $taxCase->id) {
            return $this->error('SKP record does not belong to this tax case', 422);
        }

        if ($skpRecord->status === 'approved') {
            return $this->error('SKP record already approved', 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $skpRecord->update([
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'status' => 'approved',
            'notes' => $validated['notes'] ?? $skpRecord->notes,
        ]);

        // Update tax case with next stage
        $nextStage = $skpRecord->next_stage ?? $this->determineNextStageFromSkpType($skpRecord->skp_type);
        $taxCase->update([
            'current_stage' => $nextStage,
        ]);

        // Log workflow
        $taxCase->workflowHistories()->create([
            'stage_from' => 4,
            'stage_to' => $nextStage,
            'action' => 'approved',
            'decision_point' => 'skp_type',
            'decision_value' => $skpRecord->skp_type,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        $stageName = $nextStage === 5 ? 'Objection' : 'Refund';

        return $this->success(
            $skpRecord->fresh(['taxCase', 'approvedBy']),
            "SKP record approved and case routed to Stage {$nextStage} ({$stageName})"
        );
    }

    /**
     * Get SKP record for a tax case
     */
    public function show(TaxCase $taxCase): JsonResponse
    {
        $skpRecord = $taxCase->skpRecord()->with(['submittedBy', 'approvedBy'])->first();

        if (!$skpRecord) {
            return $this->error('No SKP record found for this tax case', 404);
        }

        return $this->success($skpRecord);
    }

    /**
     * Determine next stage based on SKP type
     * LB (Lebih Bayar) → Stage 12 (Refund)
     * NIHIL / KB → Stage 5 (Objection)
     */
    private function determineNextStageFromSkpType(string $skpType): int
    {
        return match($skpType) {
            'LB' => 12,           // Refund process
            'NIHIL', 'KB' => 5,   // Objection
            default => 5,
        };
    }
}
