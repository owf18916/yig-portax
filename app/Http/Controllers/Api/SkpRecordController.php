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
     * DECISION POINT: User's explicit choice (user_routing_choice) determines next stage
     * NOT based on skp_type - user has explicit control
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
            'royalty_correction' => 'nullable|numeric|min:0',
            'service_correction' => 'nullable|numeric|min:0',
            'other_correction' => 'nullable|numeric|min:0',
            'correction_notes' => 'nullable|string',
            'user_routing_choice' => 'required|in:refund,objection',
        ]);

        $validated['tax_case_id'] = $taxCase->id;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();
        $validated['status'] = 'submitted';

        // Determine next stage based on USER'S CHOICE, NOT skp_type
        $nextStageId = $this->determineNextStageFromUserChoice($validated['user_routing_choice']);
        $validated['next_stage_id'] = $nextStageId;

        $skpRecord = SkpRecord::create($validated);

        // Update tax case with next stage
        $taxCase->update([
            'next_stage_id' => $nextStageId,
        ]);

        // Log workflow with user's decision
        $taxCase->workflowHistories()->create([
            'stage_from' => 4,
            'stage_to' => 4,
            'action' => 'submitted',
            'decision_point' => 'user_routing_choice',
            'decision_value' => $validated['user_routing_choice'],
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
     * Approve SKP record and route to next stage based on user's choice
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
            'correction_notes' => 'nullable|string',
        ]);

        $skpRecord->update([
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'status' => 'approved',
            'correction_notes' => $validated['correction_notes'] ?? $skpRecord->correction_notes,
        ]);

        // Next stage determined by user's routing choice, NOT skp_type
        $nextStageId = $skpRecord->next_stage_id ?? $this->determineNextStageFromUserChoice($skpRecord->user_routing_choice);
        $taxCase->update([
            'next_stage_id' => $nextStageId,
        ]);

        // Log workflow
        $taxCase->workflowHistories()->create([
            'stage_from' => 4,
            'stage_to' => $nextStageId,
            'action' => 'approved',
            'decision_point' => 'user_routing_choice',
            'decision_value' => $skpRecord->user_routing_choice,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        $stageName = $nextStageId === 5 ? 'Objection (Stage 5)' : 'Refund (Stage 13)';

        return $this->success(
            $skpRecord->fresh(['taxCase', 'approvedBy']),
            "SKP record approved and case routed to {$stageName}"
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
     * Determine next stage based on user's explicit choice (NOT skp_type)
     * 'refund' → Stage 13 (Bank Transfer Request)
     * 'objection' → Stage 5 (Surat Keberatan)
     */
    private function determineNextStageFromUserChoice(string $userChoice): int
    {
        return match($userChoice) {
            'refund' => 13,       // Bank Transfer Request
            'objection' => 5,     // Surat Keberatan (Objection)
            default => 5,         // Default to Objection if invalid
        };
    }
}
