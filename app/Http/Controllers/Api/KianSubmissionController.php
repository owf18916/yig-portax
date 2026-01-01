<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\KianSubmission;
use App\Models\WorkflowHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KianSubmissionController extends ApiController
{
    /**
     * Store KIAN submission - Correction/Amendment Request (Stage 12)
     */
    public function store(Request $request, TaxCase $taxCase)
    {
        try {
            $validated = $request->validate([
                'submission_number' => 'required|string|unique:kian_submissions',
                'submission_date' => 'required|date',
                'submission_type' => 'required|in:CORRECTION_ERROR,ADDITIONAL_DATA,ADJUSTMENT_REQUEST',
                'correction_reason' => 'required|string',
                'amount_correction' => 'required|numeric',
                'supporting_documents' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $kianSubmission = KianSubmission::create([
                'tax_case_id' => $taxCase->id,
                'submission_number' => $validated['submission_number'],
                'submission_date' => $validated['submission_date'],
                'submission_type' => $validated['submission_type'],
                'correction_reason' => $validated['correction_reason'],
                'amount_correction' => $validated['amount_correction'],
                'supporting_documents' => $validated['supporting_documents'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'DRAFT',
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 12,
                'action' => 'KIAN_DRAFT_CREATED',
                'description' => 'KIAN (Correction/Amendment Request) created in draft status',
                'performed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 12,
                'status' => 'KIAN_SUBMITTED'
            ]);

            DB::commit();

            return $this->success($kianSubmission, 'KIAN submission created', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified KIAN submission
     */
    public function show(TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        if ($kianSubmission->tax_case_id !== $taxCase->id) {
            return $this->error('KIAN submission not found for this tax case', 404);
        }

        return $this->success($kianSubmission, 'KIAN submission retrieved');
    }

    /**
     * Submit KIAN to tax authority
     */
    public function submit(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        try {
            if ($kianSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('KIAN submission not found for this tax case', 404);
            }

            if ($kianSubmission->status !== 'DRAFT') {
                return $this->error('Only draft KIAN submissions can be submitted', 400);
            }

            DB::beginTransaction();

            $kianSubmission->update([
                'status' => 'SUBMITTED',
                'submitted_date' => now(),
                'submitted_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 12,
                'action' => 'KIAN_SUBMITTED',
                'description' => 'KIAN submitted to tax authority for review',
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 12,
                'status' => 'KIAN_AWAITING_RESPONSE'
            ]);

            DB::commit();

            return $this->success($kianSubmission, 'KIAN submitted, awaiting tax authority response', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Record KIAN response from tax authority
     */
    public function recordResponse(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        try {
            if ($kianSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('KIAN submission not found for this tax case', 404);
            }

            $validated = $request->validate([
                'response_status' => 'required|in:ACCEPTED,REJECTED,PARTIALLY_ACCEPTED',
                'response_date' => 'required|date',
                'approved_amount' => 'nullable|numeric|min:0',
                'response_notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $kianSubmission->update([
                'status' => 'RESPONDED',
                'response_status' => $validated['response_status'],
                'response_date' => $validated['response_date'],
                'approved_amount' => $validated['approved_amount'] ?? null,
                'response_notes' => $validated['response_notes'] ?? null,
                'responded_date' => now(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 12,
                'action' => 'KIAN_RESPONSE_RECORDED',
                'description' => 'KIAN response recorded. Status: ' . $validated['response_status'],
                'performed_by' => auth()->id(),
                'notes' => $validated['response_notes'] ?? null,
            ]);

            // Update tax case status based on response
            $finalStatus = 'KIAN_' . $validated['response_status'];
            $taxCase->update([
                'current_stage' => 12,
                'status' => $finalStatus
            ]);

            DB::commit();

            return $this->success($kianSubmission, 'KIAN response recorded', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Close KIAN case
     */
    public function close(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        try {
            if ($kianSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('KIAN submission not found for this tax case', 404);
            }

            DB::beginTransaction();

            $kianSubmission->update([
                'status' => 'CLOSED',
                'closed_date' => now(),
                'closed_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 12,
                'action' => 'KIAN_CLOSED',
                'description' => 'KIAN case closed',
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
                'next_stage' => 13,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 13,
                'status' => 'CLOSED'
            ]);

            DB::commit();

            return $this->success($kianSubmission, 'KIAN case closed, tax case finalized', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
