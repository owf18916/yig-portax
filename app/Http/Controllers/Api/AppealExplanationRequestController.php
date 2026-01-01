<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\AppealExplanationRequest;
use App\Models\WorkflowHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppealExplanationRequestController extends ApiController
{
    /**
     * Store appeal explanation request from court (Stage 9)
     */
    public function store(Request $request, TaxCase $taxCase)
    {
        try {
            $validated = $request->validate([
                'request_number' => 'required|string|unique:appeal_explanation_requests',
                'request_date' => 'required|date',
                'explanation_needed' => 'required|string',
                'deadline_date' => 'required|date|after:request_date',
                'notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $explanationRequest = AppealExplanationRequest::create([
                'tax_case_id' => $taxCase->id,
                'request_number' => $validated['request_number'],
                'request_date' => $validated['request_date'],
                'explanation_needed' => $validated['explanation_needed'],
                'deadline_date' => $validated['deadline_date'],
                'notes' => $validated['notes'] ?? null,
                'status' => 'DRAFT',
                'received_date' => now(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 9,
                'action' => 'APPEAL_EXPLANATION_RECEIVED',
                'description' => 'Appeal explanation request received from court',
                'performed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 9,
                'status' => 'APPEAL_EXPLANATION_REQUESTED'
            ]);

            DB::commit();

            return $this->success($explanationRequest, 'Appeal explanation request created', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified appeal explanation request
     */
    public function show(TaxCase $taxCase, AppealExplanationRequest $explanationRequest)
    {
        if ($explanationRequest->tax_case_id !== $taxCase->id) {
            return $this->error('Explanation request not found for this tax case', 404);
        }

        return $this->success($explanationRequest, 'Explanation request retrieved');
    }

    /**
     * Submit explanation response and move to Stage 10 (Appeal Decision)
     */
    public function submit(Request $request, TaxCase $taxCase, AppealExplanationRequest $explanationRequest)
    {
        try {
            if ($explanationRequest->tax_case_id !== $taxCase->id) {
                return $this->error('Explanation request not found for this tax case', 404);
            }

            if ($explanationRequest->status !== 'DRAFT') {
                return $this->error('Only draft explanations can be submitted', 400);
            }

            $validated = $request->validate([
                'explanation_response' => 'required|string',
                'supporting_documents' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $explanationRequest->update([
                'explanation_response' => $validated['explanation_response'],
                'supporting_documents' => $validated['supporting_documents'] ?? null,
                'status' => 'SUBMITTED',
                'submitted_date' => now(),
                'submitted_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 9,
                'action' => 'APPEAL_EXPLANATION_SUBMITTED',
                'description' => 'Appeal explanation submitted to court',
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
                'next_stage' => 10,
            ]);

            // Update tax case status - waiting for appeal decision
            $taxCase->update([
                'current_stage' => 10,
                'status' => 'APPEAL_DECISION_AWAITING'
            ]);

            DB::commit();

            return $this->success($explanationRequest, 'Explanation submitted, awaiting appeal decision', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
