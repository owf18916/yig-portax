<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\ObjectionDecision;
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
        ]);

        $validated['tax_case_id'] = $taxCase->id;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();
        $validated['status'] = 'submitted';

        // Determine next stage based on decision
        $nextStage = $this->determineNextStageFromDecision($validated['decision_type']);
        $validated['next_stage'] = $nextStage;

        $decision = ObjectionDecision::create($validated);

        // Log workflow with decision
        $taxCase->workflowHistories()->create([
            'stage_from' => 7,
            'stage_to' => 7,
            'action' => 'submitted',
            'decision_point' => 'objection_decision',
            'decision_value' => $validated['decision_type'],
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

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
