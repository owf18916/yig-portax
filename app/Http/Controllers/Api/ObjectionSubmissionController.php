<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\ObjectionSubmission;
use App\Models\WorkflowHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ObjectionSubmissionController extends ApiController
{
    /**
     * Store a newly created objection submission (Stage 5)
     */
    public function store(Request $request, TaxCase $taxCase)
    {
        try {
            $validated = $request->validate([
                'submission_date' => 'required|date',
                'submission_number' => 'required|string|unique:objection_submissions',
                'objection_reason' => 'required|string',
                'tax_amount_objected' => 'required|numeric|min:0',
                'requested_amount' => 'required|numeric|min:0',
                'supporting_documents' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $objectionSubmission = ObjectionSubmission::create([
                'tax_case_id' => $taxCase->id,
                'submission_number' => $validated['submission_number'],
                'submission_date' => $validated['submission_date'],
                'objection_reason' => $validated['objection_reason'],
                'tax_amount_objected' => $validated['tax_amount_objected'],
                'requested_amount' => $validated['requested_amount'],
                'supporting_documents' => $validated['supporting_documents'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'DRAFT',
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 5,
                'action' => 'OBJECTION_DRAFT_CREATED',
                'description' => 'Objection submission created in draft status',
                'performed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 5,
                'status' => 'OBJECTION_SUBMITTED'
            ]);

            DB::commit();

            return $this->success($objectionSubmission, 'Objection submission created', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified objection submission
     */
    public function show(TaxCase $taxCase, ObjectionSubmission $objectionSubmission)
    {
        if ($objectionSubmission->tax_case_id !== $taxCase->id) {
            return $this->error('Objection submission not found for this tax case', 404);
        }

        return $this->success($objectionSubmission, 'Objection submission retrieved');
    }

    /**
     * Submit objection and move to Stage 6 (SPUH - Response)
     */
    public function submit(Request $request, TaxCase $taxCase, ObjectionSubmission $objectionSubmission)
    {
        try {
            if ($objectionSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('Objection submission not found for this tax case', 404);
            }

            if ($objectionSubmission->status !== 'DRAFT') {
                return $this->error('Only draft objections can be submitted', 400);
            }

            DB::beginTransaction();

            $objectionSubmission->update([
                'status' => 'SUBMITTED',
                'submitted_date' => now(),
                'submitted_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 5,
                'action' => 'OBJECTION_SUBMITTED',
                'description' => 'Objection submission submitted to tax authority',
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
                'next_stage' => 6,
            ]);

            // Update tax case status - waiting for response (SPUH)
            $taxCase->update([
                'current_stage' => 6,
                'status' => 'SPUH_AWAITING'
            ]);

            DB::commit();

            return $this->success($objectionSubmission, 'Objection submitted, awaiting response', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Withdraw objection
     */
    public function withdraw(Request $request, TaxCase $taxCase, ObjectionSubmission $objectionSubmission)
    {
        try {
            if ($objectionSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('Objection submission not found for this tax case', 404);
            }

            $validated = $request->validate([
                'withdrawal_reason' => 'required|string',
            ]);

            DB::beginTransaction();

            $objectionSubmission->update([
                'status' => 'WITHDRAWN',
                'withdrawal_reason' => $validated['withdrawal_reason'],
                'withdrawn_date' => now(),
                'withdrawn_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 5,
                'action' => 'OBJECTION_WITHDRAWN',
                'description' => 'Objection submission withdrawn',
                'performed_by' => auth()->id(),
                'notes' => $validated['withdrawal_reason'],
            ]);

            // Case status reverts
            $taxCase->update([
                'current_stage' => 4,
                'status' => 'SKP_FINAL'
            ]);

            DB::commit();

            return $this->success($objectionSubmission, 'Objection withdrawn', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
