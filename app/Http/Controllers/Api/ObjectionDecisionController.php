<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\ObjectionDecision;
use App\Jobs\SendKianReminderJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ObjectionDecisionController extends ApiController
{
    /**
     * Store objection decision (Stage 7)
     * DECISION POINT: Decision type determines next stage
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Validate stage
        if ($taxCase->current_stage !== 7) {
            return $this->error('Tax case is not at Objection Decision stage', 422);
        }

        $validated = $request->validate([
            'decision_number' => 'required|string|unique:objection_decisions',
            'decision_date' => 'required|date',
            'decision_type' => 'required|in:granted,partially_granted,rejected',
            'decision_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'create_refund' => 'nullable|boolean',
            'refund_amount' => 'nullable|numeric|min:0',
            'continue_to_next_stage' => 'nullable|boolean',
        ]);

        // ⭐ CHANGE 3: Validate independent actions
        if ($validated['create_refund']) {
            $validated['refund_amount'] = $request->input('refund_amount');
            if (!$validated['refund_amount'] || $validated['refund_amount'] <= 0) {
                return $this->error('Refund amount must be greater than 0 when creating refund', 422);
            }
            
            // Validate refund amount doesn't exceed available amount
            $availableAmount = max(0, $taxCase->disputed_amount - ($taxCase->getTotalRefundedAmount() ?? 0));
            if ($validated['refund_amount'] > $availableAmount) {
                return $this->error("Refund amount cannot exceed available amount (Rp {$availableAmount})", 422);
            }
        }

        $validated['tax_case_id'] = $taxCase->id;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();
        $validated['status'] = 'submitted';

        // Determine next stage based on decision
        $nextStage = $this->determineNextStageFromDecision($validated['decision_type']);
        
        // ⭐ CHANGE 3: Override next stage if continue_to_next_stage is false
        if (!$validated['continue_to_next_stage']) {
            $nextStage = null; // Case will end
        }
        
        $validated['next_stage'] = $nextStage;

        $decision = ObjectionDecision::create($validated);

        // ⭐ CHANGE 3: Create refund if requested
        if ($validated['create_refund']) {
            $decision->createRefundIfNeeded();
        }

        // Update tax case stage (or null if case ends)
        if ($validated['continue_to_next_stage']) {
            $taxCase->update([
                'current_stage' => $nextStage,
            ]);
        }

        // Log workflow with decision
        $taxCase->workflowHistories()->create([
            'stage_id' => 7,
            'stage_from' => 7,
            'stage_to' => $nextStage,
            'action' => 'submitted',
            'decision_point' => 'independent_actions',
            'decision_value' => json_encode([
                'decision_type' => $validated['decision_type'],
                'create_refund' => $validated['create_refund'],
                'refund_amount' => $validated['refund_amount'],
                'continue_to_next_stage' => $validated['continue_to_next_stage'],
            ]),
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        // ⭐ CHANGE 4: Check if KIAN reminder email should be sent
        // KIAN is needed when decision is rejected/partially_granted and user chose not to continue
        if (!$validated['continue_to_next_stage']) {
            // ✅ NEW: Check if KIAN is needed at Stage 7 specifically
            if ($taxCase->needsKianAtStage(7)) {
                $reason = $taxCase->getKianEligibilityReasonForStage(7);
                // ✅ UPDATED: Dispatch with stage_id = 7
                dispatch(new SendKianReminderJob($taxCase, 'Stage 7 - Objection Decision (Keputusan Keberatan)', $reason, 7));
            }
        }

        return $this->success(
            $decision->load(['taxCase', 'submittedBy']),
            'Objection decision submitted successfully',
            201
        );
    }

    /**
     * Approve objection decision and route to next stage
     */
    public function approve(Request $request, TaxCase $taxCase, ObjectionDecision $decision): JsonResponse
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

        // Update tax case with next stage
        $nextStage = $decision->next_stage ?? $this->determineNextStageFromDecision($decision->decision_type);
        $taxCase->update([
            'current_stage' => $nextStage,
        ]);

        // Log workflow
        $taxCase->workflowHistories()->create([
            'stage_from' => 7,
            'stage_to' => $nextStage,
            'action' => 'approved',
            'decision_point' => 'objection_decision',
            'decision_value' => $decision->decision_type,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        $stageMapping = [
            8 => 'Appeal',
            12 => 'Refund',
        ];
        $stageName = $stageMapping[$nextStage] ?? 'Unknown';

        return $this->success(
            $decision->fresh(['taxCase', 'approvedBy']),
            "Objection decision approved and case routed to Stage {$nextStage} ({$stageName})"
        );
    }

    /**
     * Get objection decision for a tax case
     */
    public function show(TaxCase $taxCase): JsonResponse
    {
        $decision = $taxCase->objectionDecision()->with(['submittedBy', 'approvedBy'])->first();

        if (!$decision) {
            return $this->error('No objection decision found for this tax case', 404);
        }

        return $this->success($decision);
    }

    /**
     * Determine next stage based on objection decision
     * Granted → Stage 13 (Refund - Bank Transfer Request)
     * Rejected → Stage 8 (Appeal - Banding)
     * Partially Granted → null (user must choose via decision-choice endpoint)
     * 
     * CRITICAL: This method is for auto-routing only.
     * For partially_granted decisions, next_stage remains null,
     * and the user chooses via the frontend decision buttons.
     */
    private function determineNextStageFromDecision(string $decisionType): ?int
    {
        return match($decisionType) {
            'granted' => 13,                  // Bank Transfer Request
            'rejected' => 8,                  // Appeal (Banding)
            'partially_granted' => null,      // User must choose
            default => null,
        };
    }
}
