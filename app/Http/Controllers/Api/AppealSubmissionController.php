<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\AppealSubmission;
use App\Models\WorkflowHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppealSubmissionController extends ApiController
{
    /**
     * Store a newly created appeal submission (Stage 8)
     * 
     * ✅ NEW: Validate that appeal_amount doesn't exceed loss from Stage 7
     * Loss from Stage 7 = objection_amount - decision_amount
     * If no Stage 7 loss, max appeal_amount = disputed_amount
     */
    public function store(Request $request, TaxCase $taxCase)
    {
        try {
            $validated = $request->validate([
                'submission_date' => 'required|date',
                'submission_number' => 'required|string|unique:appeal_submissions',
                'appeal_reason' => 'required|string',
                'tax_amount_appealed' => 'required|numeric|min:0',
                'requested_amount' => 'required|numeric|min:0',
                'supporting_documents' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);

            // ✅ NEW: Calculate maximum allowed appeal_amount from Stage 7 loss
            $maxAllowedAmount = $taxCase->disputed_amount; // Default: full disputed amount
            
            if ($taxCase->objectionDecision && $taxCase->objectionSubmission) {
                // Stage 7 loss = objection_amount - decision_amount
                $stage7Loss = $taxCase->calculateLossAtStage(7);
                if ($stage7Loss !== null) {
                    // If there's a loss at Stage 7, max appeal cannot exceed this loss
                    $maxAllowedAmount = $stage7Loss;
                }
            }

            // ✅ NEW: Validate requested_amount doesn't exceed maximum allowed
            if ($validated['requested_amount'] > $maxAllowedAmount) {
                return $this->error(
                    "Appeal amount cannot exceed Rp " . number_format($maxAllowedAmount, 0, ',', '.') . 
                    " (maximum loss from Stage 7). Your requested: Rp " . 
                    number_format($validated['requested_amount'], 0, ',', '.'),
                    422
                );
            }

            DB::beginTransaction();

            $appealSubmission = AppealSubmission::create([
                'tax_case_id' => $taxCase->id,
                'submission_number' => $validated['submission_number'],
                'submission_date' => $validated['submission_date'],
                'appeal_reason' => $validated['appeal_reason'],
                'tax_amount_appealed' => $validated['tax_amount_appealed'],
                'requested_amount' => $validated['requested_amount'],
                'supporting_documents' => $validated['supporting_documents'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'DRAFT',
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 8,
                'action' => 'APPEAL_DRAFT_CREATED',
                'description' => 'Appeal submission created in draft status',
                'performed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 8,
                'status' => 'APPEAL_SUBMITTED'
            ]);

            DB::commit();

            return $this->success($appealSubmission, 'Appeal submission created', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified appeal submission
     */
    public function show(TaxCase $taxCase, AppealSubmission $appealSubmission)
    {
        if ($appealSubmission->tax_case_id !== $taxCase->id) {
            return $this->error('Appeal submission not found for this tax case', 404);
        }

        return $this->success($appealSubmission, 'Appeal submission retrieved');
    }

    /**
     * Submit appeal to court
     */
    public function submit(Request $request, TaxCase $taxCase, AppealSubmission $appealSubmission)
    {
        try {
            if ($appealSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('Appeal submission not found for this tax case', 404);
            }

            if ($appealSubmission->status !== 'DRAFT') {
                return $this->error('Only draft appeals can be submitted', 400);
            }

            DB::beginTransaction();

            $appealSubmission->update([
                'status' => 'SUBMITTED',
                'submitted_date' => now(),
                'submitted_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 8,
                'action' => 'APPEAL_SUBMITTED',
                'description' => 'Appeal submission submitted to court',
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
                'next_stage' => 9,
            ]);

            // Update tax case status - waiting for explanation request
            $taxCase->update([
                'current_stage' => 9,
                'status' => 'APPEAL_EXPLANATION_AWAITING'
            ]);

            DB::commit();

            return $this->success($appealSubmission, 'Appeal submitted to court, awaiting explanation request', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Withdraw appeal
     */
    public function withdraw(Request $request, TaxCase $taxCase, AppealSubmission $appealSubmission)
    {
        try {
            if ($appealSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('Appeal submission not found for this tax case', 404);
            }

            $validated = $request->validate([
                'withdrawal_reason' => 'required|string',
            ]);

            DB::beginTransaction();

            $appealSubmission->update([
                'status' => 'WITHDRAWN',
                'withdrawal_reason' => $validated['withdrawal_reason'],
                'withdrawn_date' => now(),
                'withdrawn_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 8,
                'action' => 'APPEAL_WITHDRAWN',
                'description' => 'Appeal submission withdrawn',
                'performed_by' => auth()->id(),
                'notes' => $validated['withdrawal_reason'],
            ]);

            // Case status reverts to objection decision
            $taxCase->update([
                'current_stage' => 7,
                'status' => 'OBJECTION_DECISION_FINAL'
            ]);

            DB::commit();

            return $this->success($appealSubmission, 'Appeal withdrawn', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
