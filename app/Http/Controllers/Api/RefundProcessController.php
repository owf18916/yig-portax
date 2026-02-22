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
     * ✅ REFACTORED: Get all refund processes for a tax case
     * Supports filtering by stage_id (preferred) or stage_source (deprecated)
     */
    public function index(Request $request, TaxCase $taxCase): JsonResponse
    {
        $query = $taxCase->refundProcesses()->with(['submittedBy', 'approvedBy']);

        // Filter by stage_id (preferred)
        if ($request->has('stage_id')) {
            $query->byStageId($request->getInt('stage_id'));
        }
        // Filter by stage_source (deprecated, for backward compatibility)
        elseif ($request->has('stage_source')) {
            $query->byStageSource($request->get('stage_source'));
        }

        $refunds = $query->get();

        return $this->success($refunds);
    }

    /**
     * ✅ REFACTORED: Store refund process - now supports multiple refunds per tax case
     * 
     * Key Changes:
     * - Uses stage_id (integer) as primary identifier, not stage_source (string)
     * - Enforces one refund per stage via unique constraint (tax_case_id, stage_id)
     * - Validates stage_id is valid (0, 4, 7, 10, or 12)
     * - Manual creation: Users explicitly choose to create refund
     * 
     * Accepts parameters:
     * - stage_id (required, in: 0,4,7,10,12)
     *   └─ 0: PRELIMINARY (Pengembalian Pendahuluan at Stage 1)
     *   └─ 4: SKP Decision
     *   └─ 7: Objection Decision
     *   └─ 10: Appeal Decision
     *   └─ 12: Supreme Court Decision
     * - refund_number (required, unique)
     * - refund_date (required)
     * - refund_method (required)
     * - refund_amount (required, cannot exceed available amount)
     * - refund_status (required)
     * - bank_details (optional)
     * - notes (optional)
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Check if case has available refund amount
        if (!$taxCase->canCreateRefund()) {
            return $this->error('No additional refund amount available for this tax case', 422);
        }

        $validated = $request->validate([
            // ✅ NEW: Use stage_id instead of stage_source
            'stage_id' => 'required|integer|in:0,4,7,10,12',
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
            'notes' => 'nullable|string',
        ]);

        // ✅ NEW: Validate no existing refund for this stage
        $existingRefund = RefundProcess::where('tax_case_id', $taxCase->id)
            ->where('stage_id', $validated['stage_id'])
            ->exists();

        if ($existingRefund) {
            return $this->error(
                'A refund already exists for this stage. Only one refund per stage is allowed.',
                422
            );
        }

        // Auto-generate sequence number
        $validated['sequence_number'] = RefundProcess::getNextSequenceNumber($taxCase->id);
        
        // ✅ NEW: Map stage_id to stage_source for backward compatibility during transition
        $validated['stage_source'] = $this->mapStageIdToSource($validated['stage_id']);
        
        $validated['tax_case_id'] = $taxCase->id;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();
        $validated['status'] = 'submitted';

        $refundProcess = RefundProcess::create($validated);

        return $this->success(
            $refundProcess->load(['taxCase', 'submittedBy']),
            'Refund process created successfully (Stage: ' . $refundProcess->stage_label . ', Sequence: #' . $refundProcess->sequence_number . ')',
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
     * ✅ NEW: Map stage_id (integer) to stage_source (string) for backward compatibility
     * Used during transition to support both field formats
     * 
     * @param int $stageId One of: 0=PRELIMINARY, 4=SKP, 7=OBJECTION, 10=APPEAL, 12=SUPREME_COURT
     * @return string One of: PRELIMINARY, SKP, OBJECTION, APPEAL, SUPREME_COURT
     * @throws \InvalidArgumentException If stage_id is invalid
     */
    private function mapStageIdToSource(int $stageId): string
    {
        return match($stageId) {
            0 => RefundProcess::STAGE_SOURCE_PRELIMINARY,
            4 => RefundProcess::STAGE_SOURCE_SKP,
            7 => RefundProcess::STAGE_SOURCE_OBJECTION,
            10 => RefundProcess::STAGE_SOURCE_APPEAL,
            12 => RefundProcess::STAGE_SOURCE_SUPREME_COURT,
            default => throw new \InvalidArgumentException("Invalid stage_id: {$stageId}"),
        };
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
