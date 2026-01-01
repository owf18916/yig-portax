<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\SupremeCourtSubmission;
use App\Models\WorkflowHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupremeCourtSubmissionController extends ApiController
{
    /**
     * Store supreme court review submission (Stage 11)
     */
    public function store(Request $request, TaxCase $taxCase)
    {
        try {
            $validated = $request->validate([
                'submission_date' => 'required|date',
                'submission_number' => 'required|string|unique:supreme_court_submissions',
                'review_reason' => 'required|string',
                'supreme_court_reason' => 'required|in:NEW_EVIDENCE,LEGAL_ERROR,FACTUAL_ERROR,PROCEDURAL_ERROR,PUBLIC_INTEREST',
                'tax_amount_reviewed' => 'required|numeric|min:0',
                'supporting_documents' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $scSubmission = SupremeCourtSubmission::create([
                'tax_case_id' => $taxCase->id,
                'submission_number' => $validated['submission_number'],
                'submission_date' => $validated['submission_date'],
                'review_reason' => $validated['review_reason'],
                'supreme_court_reason' => $validated['supreme_court_reason'],
                'tax_amount_reviewed' => $validated['tax_amount_reviewed'],
                'supporting_documents' => $validated['supporting_documents'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'DRAFT',
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 11,
                'action' => 'SUPREME_COURT_SUBMISSION_DRAFT',
                'description' => 'Supreme court review submission created in draft status',
                'performed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 11,
                'status' => 'SUPREME_COURT_SUBMISSION_DRAFT'
            ]);

            DB::commit();

            return $this->success($scSubmission, 'Supreme court submission created', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified supreme court submission
     */
    public function show(TaxCase $taxCase, SupremeCourtSubmission $supremeCourtSubmission)
    {
        if ($supremeCourtSubmission->tax_case_id !== $taxCase->id) {
            return $this->error('Supreme court submission not found for this tax case', 404);
        }

        return $this->success($supremeCourtSubmission, 'Supreme court submission retrieved');
    }

    /**
     * Submit to supreme court and move to Stage 11b (Decision)
     */
    public function submit(Request $request, TaxCase $taxCase, SupremeCourtSubmission $supremeCourtSubmission)
    {
        try {
            if ($supremeCourtSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('Supreme court submission not found for this tax case', 404);
            }

            if ($supremeCourtSubmission->status !== 'DRAFT') {
                return $this->error('Only draft submissions can be submitted', 400);
            }

            DB::beginTransaction();

            $supremeCourtSubmission->update([
                'status' => 'SUBMITTED',
                'submitted_date' => now(),
                'submitted_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 11,
                'action' => 'SUPREME_COURT_SUBMITTED',
                'description' => 'Supreme court review submission submitted',
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
                'next_stage' => 11,
            ]);

            // Update tax case status - waiting for supreme court decision
            $taxCase->update([
                'current_stage' => 11,
                'status' => 'SUPREME_COURT_DECISION_AWAITING'
            ]);

            DB::commit();

            return $this->success($supremeCourtSubmission, 'Submitted to supreme court, awaiting decision', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Withdraw from supreme court
     */
    public function withdraw(Request $request, TaxCase $taxCase, SupremeCourtSubmission $supremeCourtSubmission)
    {
        try {
            if ($supremeCourtSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('Supreme court submission not found for this tax case', 404);
            }

            $validated = $request->validate([
                'withdrawal_reason' => 'required|string',
            ]);

            DB::beginTransaction();

            $supremeCourtSubmission->update([
                'status' => 'WITHDRAWN',
                'withdrawal_reason' => $validated['withdrawal_reason'],
                'withdrawn_date' => now(),
                'withdrawn_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 11,
                'action' => 'SUPREME_COURT_WITHDRAWN',
                'description' => 'Supreme court review submission withdrawn',
                'performed_by' => auth()->id(),
                'notes' => $validated['withdrawal_reason'],
            ]);

            // Case status reverts
            $taxCase->update([
                'current_stage' => 10,
                'status' => 'APPEAL_DECISION_FINAL'
            ]);

            DB::commit();

            return $this->success($supremeCourtSubmission, 'Withdrawn from supreme court', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
