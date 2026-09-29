<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\SkpRecord;
use App\Services\KianNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SkpRecordController extends ApiController
{
    /**
     * Store SKP record (Stage 4)
     * DECISION POINT: User's explicit choice (user_routing_choice) determines next stage
     * NOT based on skp_type - user has explicit control
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        if ($taxCase->current_stage !== 4) {
            return $this->error('Tax case is not at SKP stage', 422);
        }

        $validated = $request->validate([
            'skp_number' => 'required|string|max:255|unique:skp_records',
            'issue_date' => 'required|date',
            'receipt_date' => 'nullable|date',
            'skp_due_date' => 'nullable|date',
            'skp_type' => 'required|in:LB,NIHIL,KB',
            'skp_amount' => 'required|numeric|min:0|max:9999999999999.99',
            'royalty_correction' => 'nullable|numeric|min:0|max:9999999999999.99',
            'service_correction' => 'nullable|numeric|min:0|max:9999999999999.99',
            'other_correction' => 'nullable|numeric|min:0|max:9999999999999.99',
            'correction_notes' => 'nullable|string',
            'next_action' => 'nullable|string',
            'next_action_due_date' => 'nullable|date',
            'status_comment' => 'nullable|string',
            'user_routing_choice' => 'required|in:refund,objection',
            'create_refund' => 'nullable|boolean',
            'refund_amount' => 'nullable|numeric|min:0',
            'continue_to_next_stage' => 'nullable|boolean',
        ]);

        if (SkpRecord::withTrashed()->where('tax_case_id', $taxCase->id)->exists()) {
            return $this->error('SKP record already exists for this tax case', 422);
        }

        $validated['create_refund'] = $request->boolean('create_refund');
        $validated['continue_to_next_stage'] = $request->boolean('continue_to_next_stage');
        $validated['refund_amount'] = $validated['refund_amount'] ?? null;

        if ($validated['create_refund']) {
            if (!$validated['refund_amount'] || $validated['refund_amount'] <= 0) {
                return $this->error('Refund amount must be greater than 0 when creating refund', 422);
            }
            $availableAmount = max(0, $taxCase->disputed_amount - $validated['skp_amount']);
            if ($validated['refund_amount'] > $availableAmount) {
                return $this->error("Refund amount cannot exceed available amount (Rp {$availableAmount})", 422);
            }
        }

        $nextStageId = $validated['continue_to_next_stage']
            ? $this->determineNextStageFromUserChoice($validated['user_routing_choice'])
            : null;

        $skpRecord = DB::transaction(function () use ($request, $taxCase, $validated, $nextStageId) {
            $record = SkpRecord::create($validated + ['tax_case_id' => $taxCase->id]);

            // Preserve this alternate endpoint's existing explicit routing behavior.
            if ($validated['continue_to_next_stage']) {
                $taxCase->update(['current_stage' => $nextStageId]);
            }

            $taxCase->workflowHistories()->create([
                'stage_id' => 4,
                'stage_from' => 4,
                'stage_to' => $nextStageId,
                'action' => 'submitted',
                'status' => 'submitted',
                'decision_point' => 'independent_actions',
                'decision_value' => json_encode([
                    'create_refund' => $validated['create_refund'],
                    'refund_amount' => $validated['refund_amount'],
                    'continue_to_next_stage' => $validated['continue_to_next_stage'],
                ]),
                'user_id' => $request->user()->id,
            ]);

            // As in the generic store, persist the choice; refund creation is separate.
            return $record;
        });

        // Use the current job contract and reuse the record already created above.
        $taxCase->setRelation('skpRecord', $skpRecord);
        app(KianNotificationService::class)->evaluateFinalSubmit($taxCase, 4);

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
            return $this->error('SKP record not found for this tax case', 404);
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
