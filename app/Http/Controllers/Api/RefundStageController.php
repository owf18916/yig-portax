<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use Illuminate\Http\Request;
use App\Models\RefundProcess;
use Illuminate\Http\JsonResponse;
use App\Models\BankTransferRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * RefundStageController - Manages the Refund Process Flow Stages 1-4
 * 
 * REFUND STAGES (Independent from Main Workflow Stages 1-12):
 * 
 * Refund Stage 1: REFUND INITIATED
 *   - Refund process created
 *   - Status: pending/IN_PROGRESS
 *   - Table: refund_processes
 *   - No payment yet
 * 
 * Refund Stage 2: BANK TRANSFER REQUEST
 *   - Create bank transfer request
 *   - Document: Surat Permintaan Transfer
 *   - Status: pending → processing
 *   - Table: bank_transfer_requests
 * 
 * Refund Stage 3: TRANSFER INSTRUCTION RECEIVED
 *   - Update with bank instruction details
 *   - Document: Surat Instruksi Transfer
 *   - Track: instruction_issue_date, instruction_received_date
 *   - Status: processing → processing (awaiting payment)
 * 
 * Refund Stage 4: REFUND RECEIVED/COMPLETED
 *   - Confirm receipt of funds
 *   - Update: received_date, received_amount
 *   - Status: processing → completed
 *   - Ordinary settlement leaves the TaxCase unchanged
 * 
 * IMPORTANT:
 * - Multiple refunds allowed for PRELIMINARY stage (stage_id=0)
 * - Only ONE refund per stage for decision stages (4,7,10,12)
 */
class RefundStageController extends ApiController
{
    /**
     * Refund Stage 1: Show refund process details
     * 
     * @param TaxCase $taxCase
     * @param int $refundId - Optional specific refund process ID
     */
    public function showRefundStage1(TaxCase $taxCase, $refundId = null): JsonResponse
    {
        try {
            $refundProcess = $this->getRefundProcess($taxCase, $refundId);

            return $this->success([
                'id' => $refundProcess->id,
                'refund_number' => $refundProcess->refund_number,
                'refund_amount' => $refundProcess->refund_amount,
                'refund_method' => $refundProcess->refund_method,
                'refund_status' => $refundProcess->refund_status,
                'stage_label' => $refundProcess->stage_label,
                'is_preliminary' => $refundProcess->isPreliminary(),
                'current_stage' => $refundProcess->getCurrentRefundStage(),
                'submitted_by_name' => $refundProcess->submittedBy?->name,
                'submitted_at' => $refundProcess->submitted_at,
                'notes' => $refundProcess->notes,
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case or refund process not found', 404);
        }
    }

    /**
     * Refund Stage 1: Create new refund process
     * 
     * Multiple refunds allowed for preliminary stage (stage_id=0)
     * Only one refund per decision stage (4,7,10,12)
     * Validation is enforced in RefundProcess model boot() method
     * 
     * @param Request $request
     * @param TaxCase $taxCase
     */
    public function createRefundStage1(Request $request, TaxCase $taxCase): JsonResponse
    {
        try {
            $validated = $request->validate([
                'stage_id' => 'required|integer|in:' . implode(',', RefundProcess::VALID_STAGE_IDS),
                'refund_number' => 'required|string|unique:refund_processes',
                'refund_method' => 'required|in:BANK_TRANSFER,CHEQUE,CHECK,CASH,bank_transfer,check,credit',
                'refund_amount' => ['required', 'numeric', 'min:0'],
                'notes' => 'nullable|string',
            ]);

            // Enum mapping: Uppercase → Lowercase
            $methodMap = [
                'BANK_TRANSFER' => 'bank_transfer',
                'CHEQUE' => 'check',
                'CHECK' => 'check',
                'CASH' => 'credit',
                'bank_transfer' => 'bank_transfer',
                'check' => 'check',
                'credit' => 'credit'
            ];
            $validated['refund_method'] = $methodMap[strtoupper($validated['refund_method'])] ?? 'bank_transfer';

            // Auto-set required fields
            $validated['tax_case_id'] = $taxCase->id;
            $validated['sequence_number'] = RefundProcess::getNextSequenceNumber($taxCase->id);
            $validated['submitted_by'] = auth()->id();
            $validated['submitted_at'] = now();
            $validated['status'] = 'submitted';
            $validated['refund_status'] = 'pending';

            // Validation in model boot() will throw exception if duplicate decision stage refund
            $refundProcess = RefundProcess::create($validated);

            return $this->success(
                $refundProcess->load(['taxCase', 'submittedBy']),
                'Refund process created successfully (Refund Stage 1/4)',
                201
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('RefundStageController::createRefundStage1 error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('Failed to create refund: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Refund Stage 2: Show refund and bank transfer request data
     * 
     * First load: Returns refund data only (no bank transfer yet)
     * After submission: Returns both refund and bank transfer data
     * 
     * @param TaxCase $taxCase
     * @param int $refundId - Optional specific refund process ID
     */
    public function showRefundStage2(TaxCase $taxCase, $refundId = null): JsonResponse
    {
        try {
            $refundProcess = $this->getRefundProcess($taxCase, $refundId);
            $bankTransfer = $refundProcess->bankTransferRequests()->latest()->first();

            // Return refund data (required) + bank transfer data (optional)
            $data = [
                'refund_id' => $refundProcess->id,
                'refund_number' => $refundProcess->refund_number,
                'refund_amount' => $refundProcess->refund_amount,
                'refund_method' => $refundProcess->refund_method,
                'refund_status' => $refundProcess->refund_status,
                'current_stage' => $refundProcess->getCurrentRefundStage(),
            ];

            // Add bank transfer data if exists (after submission)
            if ($bankTransfer) {
                $data['request_number'] = $bankTransfer->request_number;
                $data['request_date'] = $bankTransfer->request_date;
                $data['transfer_number'] = $bankTransfer->transfer_number;
                $data['transfer_date'] = $bankTransfer->transfer_date;
                $data['transfer_amount'] = $bankTransfer->transfer_amount;
                $data['transfer_status'] = $bankTransfer->transfer_status;
                $data['notes'] = $bankTransfer->notes;
            }

            $data['can_submit'] = $this->canSubmitPayment($taxCase, $refundProcess, 2);

            return $this->success($data);
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case or refund process not found', 404);
        }
    }

    /**
     * Refund Stage 2: Create bank transfer request
     * 
     * This marks the submission of refund request to bank
     * Creates entry in bank_transfer_requests table with transfer_status='pending'
     * 
     * @param Request $request
     * @param TaxCase $taxCase
     * @param int $refundId - Optional specific refund process ID
     */
    public function createRefundStage2(Request $request, TaxCase $taxCase, $refundId = null): JsonResponse
    {
        // Identity is required before any fallback lookup or financial validation.
        $requestId = validator(['refund_process_id' => $refundId], ['refund_process_id' => 'required|integer|min:1'])->validate();
        $refund = $taxCase->refundProcesses()->whereKey($requestId['refund_process_id'])->firstOrFail();
        if (\App\Services\PreliminaryRefundLifecycle::concerns($taxCase, $refund)) {
            $transfer = app(\App\Services\PreliminaryRefundLifecycle::class)->payment($taxCase->id, (int) $refundId, 2, $request->all());
        } else {
            $transfer = app(\App\Services\OrdinaryRefundPayment::class)->payment($taxCase->id, $refundId, 2, $request->all());
        }

        return $this->success($transfer->load(['refundProcess', 'createdBy']), 'Payment evidence saved');
    }

    /**
     * Refund Stage 3: Show transfer instruction data
     * 
     * Display details of bank transfer and instruction
     * Fields: instruction_number, issue_date, received_date, bank details
     * 
     * First load: Returns refund data only
     * After Stage 2 submission: Returns refund + bank transfer data
     * After Stage 3 submission: Returns full instruction details
     * 
     * @param TaxCase $taxCase
     * @param int $refundId - Optional specific refund process ID
     */
    public function showRefundStage3(TaxCase $taxCase, $refundId = null): JsonResponse
    {
        try {
            $refundProcess = $this->getRefundProcess($taxCase, $refundId);
            $bankTransfer = $refundProcess->bankTransferRequests()->latest()->first();

            // Return refund data (required) + bank transfer + instruction data (optional)
            $data = [
                'refund_id' => $refundProcess->id,
                'is_preliminary' => \App\Services\PreliminaryRefundLifecycle::concerns($taxCase, $refundProcess),
                'refund_number' => $refundProcess->refund_number,
                'refund_amount' => $refundProcess->refund_amount,
                'current_stage' => $refundProcess->getCurrentRefundStage(),
            ];

            // Add bank transfer data if exists (after Stage 2 submission)
            if ($bankTransfer) {
                $data['request_number'] = $bankTransfer->request_number;
                $data['request_date'] = $bankTransfer->request_date;
                $data['transfer_number'] = $bankTransfer->transfer_number;
                $data['transfer_date'] = $bankTransfer->transfer_date;
                $data['transfer_amount'] = $bankTransfer->transfer_amount;
                $data['transfer_status'] = $bankTransfer->transfer_status;
                
                // Add instruction details if exists (after Stage 3 submission)
                $data['instruction_number'] = $bankTransfer->instruction_number;
                $data['instruction_issue_date'] = $bankTransfer->instruction_issue_date;
                $data['instruction_received_date'] = $bankTransfer->instruction_received_date;
                $data['bank_code'] = $bankTransfer->bank_code;
                $data['bank_name'] = $bankTransfer->bank_name;
                $data['account_number'] = $bankTransfer->account_number;
                $data['account_holder'] = $bankTransfer->account_holder;
                $data['account_name'] = $bankTransfer->account_name;
                $data['notes'] = $bankTransfer->notes;
            }

            $data['can_submit'] = $this->canSubmitPayment($taxCase, $refundProcess, 3);

            return $this->success($data);
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case or refund process not found', 404);
        }
    }

    /**
     * Refund Stage 3: Update transfer instruction details
     * 
     * Update bank instruction details including:
     * - instruction_number: Number of bank instruction
     * - instruction_issue_date: When instruction was issued
     * - instruction_received_date: When instruction was received
     * - Bank details (code, name, account info)
     * - transfer_status → 'processing'
     * 
     * @param Request $request
     * @param TaxCase $taxCase
     * @param int $refundId - Optional specific refund process ID
     */
    public function updateRefundStage3(Request $request, TaxCase $taxCase, $refundId = null): JsonResponse
    {
        // Identity is required before any fallback lookup or financial validation.
        $requestId = validator(['refund_process_id' => $refundId], ['refund_process_id' => 'required|integer|min:1'])->validate();
        $refund = $taxCase->refundProcesses()->whereKey($requestId['refund_process_id'])->firstOrFail();
        if (\App\Services\PreliminaryRefundLifecycle::concerns($taxCase, $refund)) {
            $transfer = app(\App\Services\PreliminaryRefundLifecycle::class)->payment($taxCase->id, (int) $refundId, 3, $request->all());
        } else {
            $transfer = app(\App\Services\OrdinaryRefundPayment::class)->payment($taxCase->id, $refundId, 3, $request->all());
        }

        return $this->success($transfer->load(['refundProcess', 'createdBy']), 'Payment evidence saved');
    }

    /**
     * Refund Stage 4: Show refund receipt confirmation
     * 
     * Display final refund completion details
     * Fields: received_date, received_amount, receipt_number
     * 
     * First load: Returns refund data only
     * After Stage 3 submission: Returns refund + instruction data
     * After Stage 4 submission: Returns full receipt details
     * 
     * @param TaxCase $taxCase
     * @param int $refundId - Optional specific refund process ID
     */
    public function showRefundStage4(TaxCase $taxCase, $refundId = null): JsonResponse
    {
        try {
            $refundProcess = $this->getRefundProcess($taxCase, $refundId);
            $bankTransfer = $refundProcess->bankTransferRequests()->latest()->first();

            // Return refund data (required) + bank transfer + receipt data (optional)
            $data = [
                'refund_id' => $refundProcess->id,
                'refund_number' => $refundProcess->refund_number,
                'refund_amount' => $refundProcess->refund_amount,
                'is_preliminary' => \App\Services\PreliminaryRefundLifecycle::concerns($taxCase, $refundProcess),
                'refund_status' => $refundProcess->refund_status,
                'current_stage' => $refundProcess->getCurrentRefundStage(),
            ];

            // Add bank transfer and receipt data if exists (after Stage 2-3 submissions)
            if ($bankTransfer) {
                $data['transfer_number'] = $bankTransfer->transfer_number;
                $data['transfer_date'] = $bankTransfer->transfer_date;
                $data['transfer_amount'] = $bankTransfer->transfer_amount;
                $data['transfer_status'] = $bankTransfer->transfer_status;
                $data['instruction_number'] = $bankTransfer->instruction_number;
                $data['instruction_issue_date'] = $bankTransfer->instruction_issue_date;
                $data['instruction_received_date'] = $bankTransfer->instruction_received_date;
                $data['receipt_number'] = $bankTransfer->receipt_number;
                $data['received_date'] = $bankTransfer->received_date;
                $data['received_amount'] = $bankTransfer->received_amount;
                $data['notes'] = $bankTransfer->notes;
            }

            $data['can_submit'] = $this->canSubmitPayment($taxCase, $refundProcess, 4);

            return $this->success($data);
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case or refund process not found', 404);
        }
    }

    /**
     * Refund Stage 4: Complete refund process
     * 
     * Final stage: Confirm receipt of refund funds
     * Updates:
     * - received_date: When funds were received
     * - received_amount: Full authorized amount received (exact decimal equality)
     * - receipt_number: Bank receipt confirmation number
     * - refund_status → 'completed'
     * - transfer_status → 'completed'
     * - TaxCase status → 'CLOSED_REFUNDED' (if preliminary)
     * 
     * @param Request $request
     * @param TaxCase $taxCase
     * @param int $refundId - Optional specific refund process ID
     */
    public function completeRefundStage4(Request $request, TaxCase $taxCase, $refundId = null): JsonResponse
    {
        // Identity is required before any fallback lookup or financial validation.
        $requestId = validator(['refund_process_id' => $refundId], ['refund_process_id' => 'required|integer|min:1'])->validate();
        $refund = $taxCase->refundProcesses()->whereKey($requestId['refund_process_id'])->firstOrFail();
        if (\App\Services\PreliminaryRefundLifecycle::concerns($taxCase, $refund)) {
            $transfer = app(\App\Services\PreliminaryRefundLifecycle::class)->payment($taxCase->id, (int) $refundId, 4, $request->all());
        } else {
            $transfer = app(\App\Services\OrdinaryRefundPayment::class)->payment($taxCase->id, $refundId, 4, $request->all());
        }

        return $this->success($transfer->load(['refundProcess', 'createdBy']), 'Payment evidence saved');
    }

    /**
     * Helper method: Get refund process by ID or latest for tax case
     * 
     * @param TaxCase $taxCase
     * @param int|null $refundId
     * @return RefundProcess
     * @throws ModelNotFoundException
     */
    private function canSubmitPayment(TaxCase $case, RefundProcess $refund, int $stage): bool
    {
        if (\App\Services\PreliminaryRefundLifecycle::concerns($case, $refund)) {
            return true; // Preserve the separate Preliminary UI contract.
        }
        return app(\App\Services\OrdinaryRefundPayment::class)->canSubmit($case, $refund, $stage);
    }

    private function getRefundProcess(TaxCase $taxCase, $refundId = null): RefundProcess
    {
        if ($refundId) {
            return RefundProcess::where('id', $refundId)
                ->where('tax_case_id', $taxCase->id)
                ->with('bankTransferRequests')
                ->firstOrFail();
        }

        return RefundProcess::where('tax_case_id', $taxCase->id)
            ->with('bankTransferRequests')
            ->latest()
            ->firstOrFail();
    }
}
