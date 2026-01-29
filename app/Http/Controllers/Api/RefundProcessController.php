<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\RefundProcess;
use App\Models\BankTransferRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RefundProcessController extends ApiController
{
    /**
     * Get all refund processes for a tax case
     * Supports filtering by stage_source
     */
    public function index(Request $request, TaxCase $taxCase): JsonResponse
    {
        $query = $taxCase->refundProcesses()->with(['submittedBy', 'approvedBy']);

        // Filter by stage_source if provided
        if ($request->has('stage_source')) {
            $query->byStageSource($request->get('stage_source'));
        }

        $refunds = $query->get();

        return $this->success($refunds);
    }

    /**
     * Store refund process - now supports multiple refunds per tax case
     * 
     * Accepts parameters:
     * - refund_number (required, unique)
     * - refund_date (required)
     * - refund_method (required)
     * - refund_amount (required, cannot exceed available amount)
     * - refund_status (required)
     * - bank_details (optional)
     * - stage_source (required for manual creation, but will be set by decision models)
     * - notes (optional)
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Check if case has available refund amount
        if (!$taxCase->canCreateRefund()) {
            return $this->error('No additional refund amount available for this tax case', 422);
        }

        $validated = $request->validate([
            'refund_number' => 'required|string|unique:refund_processes',
            'refund_date' => 'required|date',
            'refund_method' => 'required|in:BANK_TRANSFER,CHEQUE,CASH',
            'refund_amount' => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($taxCase) {
                    if ($value > $taxCase->getAvailableRefundAmount()) {
                        $fail('Refund amount cannot exceed available refund amount of ' . $taxCase->getAvailableRefundAmount());
                    }
                },
            ],
            'refund_status' => 'required|in:PENDING,PROCESSED,COMPLETED',
            'bank_details' => 'nullable|json',
            'stage_source' => 'nullable|in:PRELIMINARY,SKP,OBJECTION,APPEAL,SUPREME_COURT',
            'notes' => 'nullable|string',
        ]);

        // Auto-generate sequence number
        $validated['sequence_number'] = RefundProcess::getNextSequenceNumber($taxCase->id);
        $validated['stage_source'] = $validated['stage_source'] ?? RefundProcess::STAGE_SOURCE_SKP;
        $validated['tax_case_id'] = $taxCase->id;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();
        $validated['status'] = 'submitted';

        $refundProcess = RefundProcess::create($validated);

        return $this->success(
            $refundProcess->load(['taxCase', 'submittedBy']),
            'Refund process created successfully (Sequence: #' . $refundProcess->sequence_number . ')',
            201
        );
    }

    /**
     * Get single refund process
     */
    public function show(TaxCase $taxCase, RefundProcess $refundProcess): JsonResponse
    {
        if ($refundProcess->tax_case_id !== $taxCase->id) {
            return $this->error('Refund does not belong to this tax case', 422);
        }

        return $this->success(
            $refundProcess->load(['taxCase', 'submittedBy', 'approvedBy', 'bankTransferRequests'])
        );
    }

    /**
     * Approve refund process
     */
    public function approve(Request $request, TaxCase $taxCase, RefundProcess $refundProcess): JsonResponse
    {
        if ($refundProcess->tax_case_id !== $taxCase->id) {
            return $this->error('Refund does not belong to this tax case', 422);
        }

        if ($refundProcess->status === 'approved') {
            return $this->error('Refund already approved', 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $refundProcess->update([
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'status' => 'approved',
            'notes' => $validated['notes'] ?? $refundProcess->notes,
        ]);

        // Update refund status
        if ($refundProcess->refund_method === 'BANK_TRANSFER') {
            $refundProcess->update(['refund_status' => 'PROCESSED']);
        }

        return $this->success(
            $refundProcess->fresh(['taxCase', 'approvedBy']),
            'Refund process approved'
        );
    }

    /**
     * Get refund processes for a tax case
     * This method retrieves all refunds (use index() for the REST endpoint)
     */
    public function getAllRefunds(TaxCase $taxCase): JsonResponse
    {
        $refunds = $taxCase->refundProcesses()
            ->with(['submittedBy', 'approvedBy', 'bankTransferRequests'])
            ->get();

        return $this->success([
            'refunds' => $refunds,
            'total_refunded' => $taxCase->getTotalRefundedAmount(),
            'available_amount' => $taxCase->getAvailableRefundAmount(),
        ]);
    }

    /**
     * Add bank transfer request for refund
     */
    public function addBankTransfer(Request $request, TaxCase $taxCase, RefundProcess $refundProcess): JsonResponse
    {
        if ($refundProcess->tax_case_id !== $taxCase->id) {
            return $this->error('Refund does not belong to this tax case', 422);
        }

        if ($refundProcess->refund_method !== 'BANK_TRANSFER') {
            return $this->error('Refund method is not bank transfer', 422);
        }

        $validated = $request->validate([
            'transfer_date' => 'required|date',
            'transfer_amount' => 'required|numeric|min:0',
            'bank_code' => 'required|string',
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
            'account_name' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $validated['refund_process_id'] = $refundProcess->id;
        $validated['transfer_number'] = $this->generateTransferNumber($refundProcess->id);
        $validated['transfer_status'] = 'PENDING';
        $validated['created_by'] = auth()->id();

        $bankTransfer = BankTransferRequest::create($validated);

        return $this->success(
            $bankTransfer->load(['refundProcess', 'createdBy']),
            'Bank transfer request created successfully',
            201
        );
    }

    /**
     * Process bank transfer
     */
    public function processBankTransfer(Request $request, TaxCase $taxCase, RefundProcess $refundProcess, BankTransferRequest $transfer): JsonResponse
    {
        if ($refundProcess->tax_case_id !== $taxCase->id) {
            return $this->error('Refund does not belong to this tax case', 422);
        }

        if ($transfer->refund_process_id !== $refundProcess->id) {
            return $this->error('Transfer does not belong to this refund', 422);
        }

        if ($transfer->transfer_status !== 'PENDING') {
            return $this->error('Transfer is not in pending status', 422);
        }

        $transfer->update(['transfer_status' => 'COMPLETED']);

        // If all transfers completed, mark refund as completed
        $pendingTransfers = $refundProcess->bankTransferRequests()
            ->where('transfer_status', '!=', 'COMPLETED')
            ->count();

        if ($pendingTransfers === 0) {
            $refundProcess->update(['refund_status' => 'COMPLETED']);
        }

        return $this->success(
            $transfer->fresh(),
            'Bank transfer processed successfully'
        );
    }

    /**
     * Reject bank transfer
     */
    public function rejectBankTransfer(Request $request, TaxCase $taxCase, RefundProcess $refundProcess, BankTransferRequest $transfer): JsonResponse
    {
        if ($refundProcess->tax_case_id !== $taxCase->id) {
            return $this->error('Refund does not belong to this tax case', 422);
        }

        if ($transfer->refund_process_id !== $refundProcess->id) {
            return $this->error('Transfer does not belong to this refund', 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|min:10',
        ]);

        $transfer->update([
            'transfer_status' => 'REJECTED',
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        return $this->success(
            $transfer->fresh(),
            'Bank transfer rejected'
        );
    }

    /**
     * Get bank transfers for a refund
     */
    public function bankTransfers(TaxCase $taxCase, RefundProcess $refundProcess): JsonResponse
    {
        if ($refundProcess->tax_case_id !== $taxCase->id) {
            return $this->error('Refund does not belong to this tax case', 422);
        }

        $transfers = $refundProcess->bankTransferRequests()
            ->with(['createdBy'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($transfers);
    }

    /**
     * Generate unique transfer number
     */
    private function generateTransferNumber(int $refundId): string
    {
        $count = BankTransferRequest::where('refund_process_id', $refundId)->count() + 1;
        return sprintf('TRF-%d-%03d', $refundId, $count);
    }
}
