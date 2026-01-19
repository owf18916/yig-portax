<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\AppealDecision;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AppealDecisionController extends ApiController
{
    /**
     * Store appeal decision (Stage 10)
     * CRITICAL: Uses user_routing_choice (like Stage 4 SKP) not automatic routing
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Validate stage
        if ($taxCase->current_stage !== 10) {
            return $this->error('Tax case is not at Appeal Decision stage', 422);
        }

        $validated = $request->validate([
            'keputusan_banding_number' => 'required|string',
            'keputusan_banding_date' => 'required|date',
            'keputusan_banding' => 'required|in:dikabulkan,dikabulkan_sebagian,ditolak',
            'keputusan_banding_amount' => 'required|numeric|min:0',
            'keputusan_banding_notes' => 'nullable|string',
            'user_routing_choice' => 'required|in:refund,supreme_court', // User explicit choice
            'decision_letter_number' => 'nullable|string',
            'decision_date' => 'nullable|date',
            'decision_type' => 'nullable|in:granted,partially_granted,rejected,skp_kb',
            'decision_amount' => 'nullable|numeric|min:0',
            'reasoning' => 'nullable|string|min:20',
            'notes' => 'nullable|string',
        ]);

        $validated['tax_case_id'] = $taxCase->id;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();
        $validated['status'] = 'submitted';

        // Determine next stage based on USER'S EXPLICIT CHOICE (not decision type)
        $nextStage = $this->determineNextStageFromUserChoice($validated['user_routing_choice']);
        $validated['next_stage'] = $nextStage;

        $decision = AppealDecision::create($validated);

        // Log workflow with decision routing
        $taxCase->update(['next_stage_id' => $nextStage]);
        
        $taxCase->workflowHistories()->create([
            'stage_from' => 10,
            'stage_to' => $nextStage,
            'action' => 'submitted',
            'decision_point' => 'appeal_decision',
            'decision_value' => $validated['keputusan_banding'],
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        return $this->success(
            $decision->load(['taxCase', 'submittedBy']),
            "Appeal decision submitted. Case routed to Stage {$nextStage}",
            201
        );
    }

    /**
     * Update appeal decision (Stage 10 - Draft Save or Revision Approval)
     */
    public function update(Request $request, TaxCase $taxCase, AppealDecision $decision): JsonResponse
    {
        $validated = $request->validate([
            'keputusan_banding_number' => 'nullable|string',
            'keputusan_banding_date' => 'nullable|date',
            'keputusan_banding' => 'nullable|in:dikabulkan,dikabulkan_sebagian,ditolak',
            'keputusan_banding_amount' => 'nullable|numeric|min:0',
            'keputusan_banding_notes' => 'nullable|string',
            'user_routing_choice' => 'nullable|in:refund,supreme_court',
            'decision_letter_number' => 'nullable|string',
            'decision_date' => 'nullable|date',
            'decision_type' => 'nullable|in:granted,partially_granted,rejected,skp_kb',
            'decision_amount' => 'nullable|numeric|min:0',
            'reasoning' => 'nullable|string|min:20',
            'notes' => 'nullable|string',
        ]);

        $decision->update($validated);

        // If user_routing_choice is provided, update next_stage
        if (isset($validated['user_routing_choice']) && $validated['user_routing_choice']) {
            $nextStage = $this->determineNextStageFromUserChoice($validated['user_routing_choice']);
            $decision->update(['next_stage' => $nextStage]);
            $taxCase->update(['next_stage_id' => $nextStage]);
        }

        return $this->success($decision->fresh(), 'Appeal decision updated successfully');
    }    /**
     * Approve appeal decision and route to next stage
     */
    public function approve(Request $request, TaxCase $taxCase, AppealDecision $decision): JsonResponse
    {
        if ($decision->tax_case_id !== $taxCase->id) {
            return $this->error('Decision does not belong to this tax case', 422);
        }

        if ($decision->status === 'approved') {
            return $this->error('Decision already approved', 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $decision->update([
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'status' => 'approved',
            'notes' => $validated['notes'] ?? $decision->notes,
        ]);

        // Update tax case with next stage from user_routing_choice
        $nextStage = $decision->next_stage ?? ($decision->user_routing_choice 
            ? $this->determineNextStageFromUserChoice($decision->user_routing_choice)
            : $this->determineNextStageFromDecision($decision->decision_type ?? 'rejected')
        );
        
        $taxCase->update([
            'current_stage' => $nextStage,
            'next_stage_id' => $nextStage,
        ]);

        // Log workflow
        $taxCase->workflowHistories()->create([
            'stage_from' => 10,
            'stage_to' => $nextStage,
            'action' => 'approved',
            'decision_point' => 'appeal_decision',
            'decision_value' => $decision->keputusan_banding ?? $decision->decision_type,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        $stageMapping = [
            11 => 'Supreme Court (Peninjauan Kembali)',
            13 => 'Refund (Bank Transfer)',
        ];
        $stageName = $stageMapping[$nextStage] ?? 'Unknown';

        return $this->success(
            $decision->fresh(['taxCase', 'approvedBy']),
            "Appeal decision approved and case routed to Stage {$nextStage} ({$stageName})"
        );
    }

    /**
     * Get appeal decision for a tax case
     */
    public function show(TaxCase $taxCase): JsonResponse
    {
        $decision = $taxCase->appealDecision()->with(['submittedBy', 'approvedBy'])->first();

        if (!$decision) {
            return $this->error('No appeal decision found for this tax case', 404);
        }

        return $this->success($decision);
    }

    /**
     * Determine next stage based on user's explicit routing choice
     * refund → Stage 13 (Bank Transfer Request)
     * supreme_court → Stage 11 (Peninjauan Kembali)
     * 
     * CRITICAL: This mirrors Stage 4 (SKP) pattern - user choice, not automatic
     */
    private function determineNextStageFromUserChoice(string $userChoice): int
    {
        return match($userChoice) {
            'refund' => 13,           // Bank Transfer Request
            'supreme_court' => 11,    // Peninjauan Kembali
            default => 11,
        };
    }

    /**
     * Determine next stage based on appeal decision (LEGACY - kept for backward compatibility)
     * Granted / Partially Granted → Stage 13 (Refund)
     * Rejected / SKP KB → Stage 11 (Supreme Court)
     */
    private function determineNextStageFromDecision(string $decisionType): int
    {
        return match($decisionType) {
            'granted', 'partially_granted' => 13,  // Refund process
            'rejected', 'skp_kb' => 11,            // Supreme Court
            default => 11,
        };
    }
}
