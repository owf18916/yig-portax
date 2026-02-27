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
 *   - TaxCase status: → CLOSED_REFUNDED
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
        try {
            $validated = $request->validate([
                'request_number' => 'required|string',
                'request_date' => 'required|date',
                'transfer_date' => 'required|date',
                'notes' => 'nullable|string',
            ]);

            $refundProcess = $this->getRefundProcess($taxCase, $refundId);

            // Check if already has a bank transfer request (avoid duplicates in stage 2)
            $existingTransfer = $refundProcess->bankTransferRequests()
                ->whereNull('deleted_at')
                ->first();

            if ($existingTransfer) {
                return $this->success(
                    $existingTransfer->fresh()->load(['refundProcess', 'createdBy']),
                    'Bank transfer request already exists for this refund. Updating Refund Stage 2...',
                    200
                );
            }

            // Create bank transfer request (Refund Stage 2)
            $bankTransfer = BankTransferRequest::create([
                'refund_process_id' => $refundProcess->id,
                'request_number' => $validated['request_number'],
                'request_date' => $validated['request_date'],
                'transfer_number' => 'TRN-' . $refundProcess->id . '-' . now()->timestamp,
                'transfer_date' => $validated['transfer_date'],
                'transfer_amount' => $refundProcess->refund_amount,  // Transfer amount = refund amount
                'transfer_status' => 'pending',  // Refund Stage 2: Transfer request submitted
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Update refund_processes status to show we're in stage 2
            $refundProcess->update([
                'refund_status' => 'approved',  // Moving to next stage
            ]);

            return $this->success(
                $bankTransfer->fresh()->load(['refundProcess', 'createdBy']),
                'Bank transfer request created successfully (Refund Stage 2/4)',
                201
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case or refund process not found', 404);
        } catch (\Exception $e) {
            Log::error('RefundStageController::createRefundStage2 error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('Failed to create bank transfer request: ' . $e->getMessage(), 500);
        }
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
        try {
            $validated = $request->validate([
                'instruction_number' => 'required|string',
                'instruction_issue_date' => 'required|date',
                'instruction_received_date' => 'required|date',
                'bank_code' => 'nullable|string',
                'bank_name' => 'nullable|string',
                'account_number' => 'nullable|string',
                'account_holder' => 'nullable|string',
                'account_name' => 'nullable|string',
                'transfer_amount' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string',
            ]);

            $refundProcess = $this->getRefundProcess($taxCase, $refundId);
            $bankTransfer = $refundProcess->bankTransferRequests()->latest()->first();

            if (!$bankTransfer) {
                return $this->error('No bank transfer request found. Please complete Refund Stage 2 first.', 422);
            }

            // Update bank transfer with instruction details (Refund Stage 3)
            $bankTransfer->update([
                'instruction_number' => $validated['instruction_number'],
                'instruction_issue_date' => $validated['instruction_issue_date'],
                'instruction_received_date' => $validated['instruction_received_date'],
                'bank_code' => $validated['bank_code'] ?? $bankTransfer->bank_code,
                'bank_name' => $validated['bank_name'] ?? $bankTransfer->bank_name,
                'account_number' => $validated['account_number'] ?? $bankTransfer->account_number,
                'account_holder' => $validated['account_holder'] ?? $bankTransfer->account_holder,
                'account_name' => $validated['account_name'] ?? $bankTransfer->account_name,
                'transfer_amount' => $validated['transfer_amount'] ?? $bankTransfer->transfer_amount,
                'transfer_status' => 'processing',  // Refund Stage 3: Instruction received, awaiting payment
                'notes' => $validated['notes'] ?? $bankTransfer->notes,
            ]);

            // Update refund status to show progression
            $refundProcess->update([
                'refund_status' => 'processed',
            ]);

            return $this->success(
                $bankTransfer->fresh()->load(['refundProcess', 'createdBy']),
                'Transfer instruction updated successfully (Refund Stage 3/4)',
                200
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case or refund process not found', 404);
        } catch (\Exception $e) {
            Log::error('RefundStageController::updateRefundStage3 error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('Failed to update transfer instruction: ' . $e->getMessage(), 500);
        }
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
     * - received_amount: Actual amount received (may differ from requested)
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
        try {
            $validated = $request->validate([
                'receipt_number' => 'required|string',
                'received_date' => 'required|date',
                'received_amount' => 'required|numeric|min:0',
                'notes' => 'nullable|string',
            ]);

            $refundProcess = $this->getRefundProcess($taxCase, $refundId);
            $bankTransfer = $refundProcess->bankTransferRequests()->latest()->first();

            if (!$bankTransfer) {
                return $this->error('No bank transfer found. Please complete Refund Stages 2-3 first.', 422);
            }

            // Validate received amount doesn't exceed transfer amount by unreasonable margin
            if ($validated['received_amount'] > $bankTransfer->transfer_amount * 1.1) {
                return $this->error(
                    'Received amount cannot exceed transfer amount by more than 10%. ' .
                    'Transfer: ' . $bankTransfer->transfer_amount . ', Received: ' . $validated['received_amount'],
                    422
                );
            }

            // Complete refund process - Refund Stage 4 (FINAL)
            $bankTransfer->update([
                'receipt_number' => $validated['receipt_number'],
                'received_date' => $validated['received_date'],
                'received_amount' => $validated['received_amount'],
                'transfer_status' => 'completed',
                'notes' => $validated['notes'] ?? $bankTransfer->notes,
            ]);

            // Update refund process to completed
            $refundProcess->update([
                'refund_status' => 'completed',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            // For PRELIMINARY refund, update TaxCase status to CLOSED_REFUNDED
            if ($refundProcess->isPreliminary()) {
                $taxCase->update([
                    'status' => 'CLOSED_REFUNDED',
                    'completed_date' => now(),
                    'is_completed' => true,
                ]);
            }

            return $this->success(
                $bankTransfer->fresh()->load(['refundProcess', 'createdBy']),
                'Refund completed successfully (Refund Stage 4/4 - CLOSED)',
                200
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case or refund process not found', 404);
        } catch (\Exception $e) {
            Log::error('RefundStageController::completeRefundStage4 error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('Failed to complete refund: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Helper method: Get refund process by ID or latest for tax case
     * 
     * @param TaxCase $taxCase
     * @param int|null $refundId
     * @return RefundProcess
     * @throws ModelNotFoundException
     */
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
