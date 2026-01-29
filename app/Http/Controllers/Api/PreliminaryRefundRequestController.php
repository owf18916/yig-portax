<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\PreliminaryRefundRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PreliminaryRefundRequestController extends ApiController
{
    /**
     * Get preliminary refund request for a tax case
     */
    public function show(TaxCase $taxCase): JsonResponse
    {
        $request = $taxCase->preliminaryRefundRequest()->with(['taxCase', 'submittedBy', 'approvedBy'])->first();

        if (!$request) {
            return $this->error('No preliminary refund request found for this tax case', 404);
        }

        return $this->success($request);
    }

    /**
     * Create preliminary refund request
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Check if case already has a preliminary refund request
        if ($taxCase->preliminaryRefundRequest()->exists()) {
            return $this->error('This tax case already has a preliminary refund request', 422);
        }

        // Validate input
        $validated = $request->validate([
            'submission_date' => 'required|date',
            'requested_amount' => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($taxCase) {
                    if ($value > $taxCase->getAvailableRefundAmount()) {
                        $fail('Requested amount cannot exceed available refund amount of ' . $taxCase->getAvailableRefundAmount());
                    }
                },
            ],
            'request_number' => 'nullable|string|unique:preliminary_refund_requests',
            'notes' => 'nullable|string',
        ]);

        // Generate request number if not provided
        if (empty($validated['request_number'])) {
            $validated['request_number'] = 'PRR-' . now()->format('YmdHis') . '-' . $taxCase->id;
        }

        $validated['tax_case_id'] = $taxCase->id;
        $validated['approval_status'] = PreliminaryRefundRequest::STATUS_PENDING;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();

        $preliminaryRequest = PreliminaryRefundRequest::create($validated);

        return $this->success(
            $preliminaryRequest->load(['taxCase', 'submittedBy']),
            'Preliminary refund request created successfully',
            201
        );
    }

    /**
     * Approve preliminary refund request
     */
    public function approve(Request $request, TaxCase $taxCase, PreliminaryRefundRequest $preliminaryRequest): JsonResponse
    {
        if ($preliminaryRequest->tax_case_id !== $taxCase->id) {
            return $this->error('Request does not belong to this tax case', 422);
        }

        if (!$preliminaryRequest->isPending()) {
            return $this->error('Only pending requests can be approved', 422);
        }

        $validated = $request->validate([
            'approved_amount' => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($preliminaryRequest) {
                    if ($value > $preliminaryRequest->requested_amount) {
                        $fail('Approved amount cannot exceed requested amount');
                    }
                },
            ],
        ]);

        // Approve and create refund
        $preliminaryRequest->approve($validated['approved_amount']);

        return $this->success(
            $preliminaryRequest->fresh(['taxCase', 'approvedBy']),
            'Preliminary refund request approved and refund process created'
        );
    }

    /**
     * Reject preliminary refund request
     */
    public function reject(Request $request, TaxCase $taxCase, PreliminaryRefundRequest $preliminaryRequest): JsonResponse
    {
        if ($preliminaryRequest->tax_case_id !== $taxCase->id) {
            return $this->error('Request does not belong to this tax case', 422);
        }

        if (!$preliminaryRequest->isPending()) {
            return $this->error('Only pending requests can be rejected', 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        // Reject the request
        $preliminaryRequest->reject($validated['reason']);

        // TODO: Trigger KIAN reminder email job

        return $this->success(
            $preliminaryRequest->fresh(),
            'Preliminary refund request rejected'
        );
    }

    /**
     * Update preliminary refund request
     */
    public function update(Request $request, TaxCase $taxCase, PreliminaryRefundRequest $preliminaryRequest): JsonResponse
    {
        if ($preliminaryRequest->tax_case_id !== $taxCase->id) {
            return $this->error('Request does not belong to this tax case', 422);
        }

        if (!$preliminaryRequest->isPending()) {
            return $this->error('Only pending requests can be updated', 422);
        }

        $validated = $request->validate([
            'submission_date' => 'sometimes|date',
            'requested_amount' => [
                'sometimes',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($taxCase) {
                    if ($value > $taxCase->getAvailableRefundAmount()) {
                        $fail('Requested amount cannot exceed available refund amount');
                    }
                },
            ],
            'notes' => 'nullable|string',
        ]);

        $preliminaryRequest->update($validated);

        return $this->success(
            $preliminaryRequest->fresh(),
            'Preliminary refund request updated successfully'
        );
    }

    /**
     * Delete preliminary refund request (only if pending)
     */
    public function destroy(TaxCase $taxCase, PreliminaryRefundRequest $preliminaryRequest): JsonResponse
    {
        if ($preliminaryRequest->tax_case_id !== $taxCase->id) {
            return $this->error('Request does not belong to this tax case', 422);
        }

        if (!$preliminaryRequest->isPending()) {
            return $this->error('Only pending requests can be deleted', 422);
        }

        $preliminaryRequest->delete();

        return $this->success(null, 'Preliminary refund request deleted successfully');
    }
}
