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
     * DECISION POINT: Decision type determines routing
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Validate stage
        if ($taxCase->current_stage !== 10) {
            return $this->error('Tax case is not at Appeal Decision stage', 422);
        }

        $validated = $request->validate([
            'decision_letter_number' => 'required|string|unique:appeal_decisions',
            'decision_date' => 'required|date',
            'decision_type' => 'required|in:granted,partially_granted,rejected,skp_kb',
            'decision_amount' => 'required|numeric|min:0',
            'reasoning' => 'required|string|min:20',
            'notes' => 'nullable|string',
        ]);

        $validated['tax_case_id'] = $taxCase->id;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();
        $validated['status'] = 'submitted';

        // Determine next stage based on decision
        $nextStage = $this->determineNextStageFromDecision($validated['decision_type']);
        $validated['next_stage'] = $nextStage;

        $decision = AppealDecision::create($validated);

        // Log workflow with decision
        $taxCase->workflowHistories()->create([
            'stage_from' => 10,
            'stage_to' => 10,
            'action' => 'submitted',
            'decision_point' => 'appeal_decision',
            'decision_value' => $validated['decision_type'],
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        return $this->success(
            $decision->load(['taxCase', 'submittedBy']),
            'Appeal decision submitted successfully',
            201
        );
    }

    /**
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

        // Update tax case with next stage
        $nextStage = $decision->next_stage ?? $this->determineNextStageFromDecision($decision->decision_type);
        $taxCase->update([
            'current_stage' => $nextStage,
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
            11 => 'Supreme Court',
            12 => 'Refund',
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
     * Determine next stage based on appeal decision
     * Granted / Partially Granted → Stage 12 (Refund)
     * Rejected / SKP KB → Stage 11 (Supreme Court)
     */
    private function determineNextStageFromDecision(string $decisionType): int
    {
        return match($decisionType) {
            'granted', 'partially_granted' => 12,  // Refund process
            'rejected', 'skp_kb' => 11,             // Supreme Court
            default => 11,
        };
    }
}
