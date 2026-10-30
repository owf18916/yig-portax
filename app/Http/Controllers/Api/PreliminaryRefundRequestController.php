<?php

namespace App\Http\Controllers\Api;

use App\Models\PreliminaryRefundRequest;
use App\Models\TaxCase;
use App\Services\PreliminaryRefundLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreliminaryRefundRequestController extends ApiController
{
    public function show(TaxCase $taxCase): JsonResponse
    {
        $request = $taxCase->preliminaryRefundRequest()->withTrashed()->with(['submittedBy', 'approvedBy', 'submissionDocument', 'decisionDocument'])->first();
        if (! $request) {
            return $this->error('No preliminary refund request found for this tax case', 404);
        }

        return $this->success($request);
    }

    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        $this->validateCommand($request);
        $record = app(PreliminaryRefundLifecycle::class)->submit($taxCase->id, $request->all(), $request->file('document'));

        return $this->success($record->load(['submittedBy', 'submissionDocument']), 'Submission recorded with evidence', 201);
    }

    public function approve(Request $request, TaxCase $taxCase, PreliminaryRefundRequest $preliminaryRequest): JsonResponse
    {
        return $this->decision($request, $taxCase, $preliminaryRequest, 'approve');
    }

    public function reject(Request $request, TaxCase $taxCase, PreliminaryRefundRequest $preliminaryRequest): JsonResponse
    {
        return $this->decision($request, $taxCase, $preliminaryRequest, 'reject');
    }

    private function decision(Request $request, TaxCase $taxCase, PreliminaryRefundRequest $preliminaryRequest, string $outcome): JsonResponse
    {
        abort_unless((int) $preliminaryRequest->tax_case_id === $taxCase->id, 404);
        $this->validateCommand($request);
        $record = app(PreliminaryRefundLifecycle::class)->decide($taxCase->id, $preliminaryRequest->id, $outcome,
            $request->input('approved_amount'), $request->input('reason'), $request->input('decision_date'), $request->file('document'));

        return $this->success($record->load(['approvedBy', 'submissionDocument', 'decisionDocument']), 'Tax Office decision recorded');
    }

    private function validateCommand(Request $request): void
    {
        $request->validate([
            'document' => 'required|file|extensions:pdf|mimetypes:application/pdf|max:10240',
            'approval_status' => 'prohibited', 'status' => 'prohibited',
            'submission_document_id' => 'prohibited', 'decision_document_id' => 'prohibited',
            'documentable_id' => 'prohibited', 'documentable_type' => 'prohibited',
        ]);
    }

    public function postpone(Request $request, TaxCase $taxCase, PreliminaryRefundRequest $preliminaryRequest): JsonResponse
    {
        return $this->error('POSTPONED is historical only. Record APPROVED or REJECTED for a documented SUBMITTED request.', 422);
    }

    public function update(Request $request, TaxCase $taxCase, PreliminaryRefundRequest $preliminaryRequest): JsonResponse
    {
        return $this->error('Recorded submissions and historical requests are read-only. Separate review is required for corrections.', 422);
    }

    public function destroy(TaxCase $taxCase, PreliminaryRefundRequest $preliminaryRequest): JsonResponse
    {
        return $this->error('Authoritative and historical request evidence cannot be deleted.', 422);
    }
}
