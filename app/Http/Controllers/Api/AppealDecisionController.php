<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\AppealDecision;
use App\Jobs\SendKianReminderJob;
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

        // Accept both Indonesian and English field names for compatibility
        $validated = $request->validate([
            'decision_number' => 'required|string',
            'decision_date' => 'required|date',
            'decision_type' => 'required|in:granted,partially_granted,rejected,skp_kb',
            'decision_amount' => 'required|numeric|min:0',
            'decision_notes' => 'nullable|string',
            'user_routing_choice' => 'required|in:refund,supreme_court', // User explicit choice
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

        // Map request fields to database field names
        $dbData = [
            'tax_case_id' => $taxCase->id,
            'decision_number' => $validated['decision_number'],
            'decision_date' => $validated['decision_date'],
            'decision_type' => $validated['decision_type'],
            'decision_amount' => $validated['decision_amount'],
            'decision_notes' => $validated['decision_notes'] ?? null,
            'submitted_by' => auth()->id(),
            'submitted_at' => now(),
            'status' => 'submitted',
            'notes' => $validated['notes'] ?? null,
            'create_refund' => $validated['create_refund'] ?? false,
            'refund_amount' => $validated['refund_amount'] ?? null,
            'continue_to_next_stage' => $validated['continue_to_next_stage'] ?? false,
        ];

        // Determine next stage based on USER'S EXPLICIT CHOICE (not decision type)
        $nextStage = $this->determineNextStageFromUserChoice($validated['user_routing_choice']);
        
        // ⭐ CHANGE 3: Override next stage if continue_to_next_stage is false
        if (!$validated['continue_to_next_stage']) {
            $nextStage = null; // Case will end
        }
        
        $dbData['next_stage'] = $nextStage;

        $decision = AppealDecision::create($dbData);

        // ⭐ CHANGE 3: Create refund if requested
        if ($validated['create_refund']) {
            $decision->createRefundIfNeeded();
        }

        // Update tax case stage
        if ($validated['continue_to_next_stage']) {
            $taxCase->update(['current_stage' => $nextStage]);
        }
        
        $taxCase->workflowHistories()->create([
            'stage_id' => 10,
            'stage_from' => 10,
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
        // KIAN is needed when decision is rejected/partially_granted and user chose not to continue to supreme court
        if (!$validated['continue_to_next_stage']) {
            $reason = $taxCase->getKianEligibilityReason();
            if ($reason) {
                dispatch(new SendKianReminderJob($taxCase, 'Appeal Decision (Stage 10)', $reason));
            }
        }

        return $this->success(
            $decision->load(['taxCase', 'submittedBy']),
            "Appeal decision submitted successfully",
            201
        );
    }

    /**
     * Update appeal decision (Stage 10 - Draft Save or Revision Approval)
     */
    public function update(Request $request, TaxCase $taxCase, AppealDecision $decision): JsonResponse
    {
        $validated = $request->validate([
            'decision_number' => 'nullable|string',
            'decision_date' => 'nullable|date',
            'decision_type' => 'nullable|in:granted,partially_granted,rejected,skp_kb',
            'decision_amount' => 'nullable|numeric|min:0',
            'decision_notes' => 'nullable|string',
            'user_routing_choice' => 'nullable|in:refund,supreme_court',
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
            'decision_value' => $decision->decision_type,
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
