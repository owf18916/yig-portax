<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\AppealDecision;
use App\Jobs\SendKianReminderJob;
use App\Services\DecisionPointRefundService;
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
            'notes' => 'nullable|string',
            'create_refund' => 'nullable|boolean',
            'refund_amount' => 'nullable|numeric|min:0',
            'continue_to_next_stage' => 'nullable|boolean',
        ]);

        // ⭐ CHANGE 3: Validate independent actions
        $validated['create_refund'] = $request->boolean('create_refund');
        $validated['continue_to_next_stage'] = $request->boolean('continue_to_next_stage');
        $validated['refund_amount'] = $validated['refund_amount'] ?? null;

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

        $nextStage = $validated['continue_to_next_stage'] ? 11 : null;
        
        $dbData['next_stage'] = $nextStage;

        $decision = AppealDecision::create($dbData);

        // ⭐ CHANGE 3: Create refund if requested
        app(DecisionPointRefundService::class)->createIfRequested($decision, 10, auth()->id());

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

        // ✅ FIXED: Check if KIAN reminder email should be sent
        // KIAN is needed WHENEVER loss exists at Stage 10, REGARDLESS of next stage choice
        if ($taxCase->needsKianAtStage(10)) {
            $reason = $taxCase->getKianEligibilityReasonForStage(10);
            if ($reason) {
                $caseId = (int) $taxCase->id;
                dispatch(new SendKianReminderJob($caseId, 'Stage 10 - Appeal Decision (Keputusan Banding)', $reason, 10));
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

        return $this->success($decision->fresh(), 'Appeal decision updated successfully');
    }    /**
     * Approve appeal decision and route to next stage
     */
    public function approve(Request $request, TaxCase $taxCase, AppealDecision $decision): JsonResponse
    {
        if ($decision->tax_case_id !== $taxCase->id) {
            return $this->error('Decision not found for this tax case', 404);
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
        $nextStage = $decision->continue_to_next_stage ? 11 : null;
        if ($nextStage) {
            $taxCase->update(['current_stage' => $nextStage]);
        }

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
            11 => 'Supreme Court Submission',
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
