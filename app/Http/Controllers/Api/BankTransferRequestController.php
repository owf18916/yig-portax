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
 * BankTransferRequestController - Handles refund workflow stages 13, 14, 15
 * 
 * Stage 13: Bank Transfer Request - Initial refund request submission
 * Stage 14: Surat Instruksi Transfer - Transfer instruction with bank details
 * Stage 15: Refund Received - Confirm receipt of refund
 */
class BankTransferRequestController extends ApiController
{
    /**
     * Stage 13: Show bank transfer request data
     */
    public function show(Request $request, TaxCase $taxCase): JsonResponse
    {
        try {
            // Get the latest refund process with bank transfer requests
            $refundProcess = $taxCase->refundProcesses()
                ->with('bankTransferRequests')
                ->latest()
                ->first();

            if (!$refundProcess) {
                return $this->error('No refund process found for this tax case', 404);
            }

            // Get the most recent bank transfer request
            $bankTransfer = $refundProcess->bankTransferRequests()
                ->latest()
                ->first();

            if (!$bankTransfer) {
                return $this->error('No bank transfer request found', 404);
            }

            return $this->success([
                'request_number' => $bankTransfer->request_number,
                'transfer_date' => $bankTransfer->transfer_date,
                'instruction_number' => $bankTransfer->instruction_number,
                'notes' => $bankTransfer->notes,
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case not found', 404);
        }
    }

    /**
     * Stage 13: Create or update bank transfer request
     */
    public function createTransferRequest(Request $request, TaxCase $taxCase): JsonResponse
    {
        try {
            $validated = $request->validate([
                'request_number' => 'required|string',
                'transfer_date' => 'required|date',
                'instruction_number' => 'nullable|string',
                'notes' => 'nullable|string',
                'documents' => 'nullable|array',
            ]);

            // Get or create refund process for this tax case
            $refundProcess = RefundProcess::firstOrCreate(
                ['tax_case_id' => $taxCase->id],
                [
                    'refund_number' => $this->generateRefundNumber($taxCase),
                    'refund_amount' => 0,
                    'refund_method' => 'bank_transfer',
                    'refund_status' => 'pending',
                    'submitted_by' => auth()->id(),
                    'submitted_at' => now(),
                    'status' => 'draft',
                ]
            );

            // Create bank transfer request (Stage 13)
            $bankTransfer = BankTransferRequest::create([
                'refund_process_id' => $refundProcess->id,
                'request_number' => $validated['request_number'],
                'transfer_number' => 'TRN-' . $refundProcess->id . '-' . time(),
                'transfer_date' => $validated['transfer_date'],
                'instruction_number' => $validated['instruction_number'],
                'notes' => $validated['notes'],
                'transfer_status' => 'pending',
                'created_by' => auth()->id(),
            ]);

            return $this->success(
                $bankTransfer->fresh()->load(['refundProcess', 'createdBy']),
                'Bank transfer request saved successfully (Stage 13)',
                201
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case not found', 404);
        } catch (\Exception $e) {
            Log::error('BankTransferRequestController::createTransferRequest error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('Failed to save data: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Stage 14: Show transfer instruction data
     */
    public function showTransferInstruction(Request $request, TaxCase $taxCase): JsonResponse
    {
        try {
            $refundProcess = $taxCase->refundProcesses()->with('bankTransferRequests')->latest()->first();
            
            if (!$refundProcess) {
                return $this->error('No refund process found', 404);
            }

            $bankTransfer = $refundProcess->bankTransferRequests()->latest()->first();

            if (!$bankTransfer) {
                return $this->error('No bank transfer found', 404);
            }

            return $this->success([
                'instruction_number' => $bankTransfer->instruction_number,
                'transfer_amount' => $bankTransfer->transfer_amount,
                'bank_code' => $bankTransfer->bank_code,
                'bank_name' => $bankTransfer->bank_name,
                'account_number' => $bankTransfer->account_number,
                'account_holder' => $bankTransfer->account_holder,
                'notes' => $bankTransfer->notes,
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case not found', 404);
        }
    }

    /**
     * Stage 14: Update transfer instruction with bank details
     */
    public function updateTransferInstruction(Request $request, TaxCase $taxCase): JsonResponse
    {
        try {
            $validated = $request->validate([
                'instruction_number' => 'required|string',
                'transfer_amount' => 'required|numeric|min:0',
                'bank_code' => 'required|string',
                'bank_name' => 'required|string',
                'account_number' => 'required|string',
                'account_holder' => 'required|string',
                'notes' => 'nullable|string',
                'documents' => 'nullable|array',
            ]);

            // Get the latest refund process
            $refundProcess = RefundProcess::where('tax_case_id', $taxCase->id)
                ->latest()
                ->first();

            if (!$refundProcess) {
                return $this->error('No refund process found. Please complete Stage 13 first.', 422);
            }

            // Get the latest bank transfer request
            $bankTransfer = $refundProcess->bankTransferRequests()
                ->latest()
                ->first();

            if (!$bankTransfer) {
                return $this->error('No bank transfer found. Please complete Stage 13 first.', 422);
            }

            // Update with bank transfer instruction details
            $bankTransfer->update([
                'instruction_number' => $validated['instruction_number'],
                'transfer_amount' => $validated['transfer_amount'],
                'bank_code' => $validated['bank_code'],
                'bank_name' => $validated['bank_name'],
                'account_number' => $validated['account_number'],
                'account_holder' => $validated['account_holder'],
                'notes' => $validated['notes'] ?? $bankTransfer->notes,
                'transfer_status' => 'processing',
            ]);

            // Update refund process with the transfer amount
            $refundProcess->update([
                'refund_amount' => $validated['transfer_amount'],
                'refund_status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'status' => 'approved',
            ]);

            return $this->success(
                $bankTransfer->fresh()->load(['refundProcess', 'createdBy']),
                'Transfer instruction saved successfully (Stage 14)',
                200
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case not found', 404);
        } catch (\Exception $e) {
            Log::error('BankTransferRequestController::updateTransferInstruction error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('Failed to save data: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Stage 15: Show refund receipt data
     */
    public function showRefundReceipt(Request $request, TaxCase $taxCase): JsonResponse
    {
        try {
            $refundProcess = $taxCase->refundProcesses()->with('bankTransferRequests')->latest()->first();
            
            if (!$refundProcess) {
                return $this->error('No refund process found', 404);
            }

            $bankTransfer = $refundProcess->bankTransferRequests()->latest()->first();

            if (!$bankTransfer) {
                return $this->error('No bank transfer found', 404);
            }

            return $this->success([
                'receipt_number' => $bankTransfer->receipt_number,
                'processed_date' => $bankTransfer->processed_date,
                'receipt_date' => $bankTransfer->transfer_date,
                'transfer_amount' => $bankTransfer->transfer_amount,
                'notes' => $bankTransfer->notes,
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case not found', 404);
        }
    }

    /**
     * Stage 15: Complete refund process with receipt confirmation
     */
    public function completeRefund(Request $request, TaxCase $taxCase): JsonResponse
    {
        try {
            $validated = $request->validate([
                'receipt_number' => 'required|string',
                'processed_date' => 'required|date',
                'receipt_date' => 'required|date',
                'transfer_amount' => 'required|numeric|min:0',
                'notes' => 'nullable|string',
                'documents' => 'nullable|array',
            ]);

            // Get the latest refund process
            $refundProcess = RefundProcess::where('tax_case_id', $taxCase->id)
                ->latest()
                ->first();

            if (!$refundProcess) {
                return $this->error('No refund process found. Please complete Stages 13-14 first.', 422);
            }

            // Get the latest bank transfer request
            $bankTransfer = $refundProcess->bankTransferRequests()
                ->latest()
                ->first();

            if (!$bankTransfer) {
                return $this->error('No bank transfer found. Please complete Stages 13-14 first.', 422);
            }

            // Update with receipt confirmation
            $bankTransfer->update([
                'receipt_number' => $validated['receipt_number'],
                'processed_date' => $validated['processed_date'],
                'receipt_date' => $validated['receipt_date'],
                'transfer_amount' => $validated['transfer_amount'],
                'notes' => $validated['notes'] ?? $bankTransfer->notes,
                'transfer_status' => 'completed',
            ]);

            // Mark refund process as completed
            $refundProcess->update([
                'refund_status' => 'completed',
                'refund_amount' => $validated['transfer_amount'],
                'processed_date' => $validated['processed_date'],
            ]);

            return $this->success(
                $bankTransfer->fresh()->load(['refundProcess', 'createdBy']),
                'Refund completed successfully (Stage 15)',
                200
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error('Validation failed', 422, $e->errors());
        } catch (ModelNotFoundException $e) {
            return $this->error('Tax case not found', 404);
        } catch (\Exception $e) {
            Log::error('BankTransferRequestController::completeRefund error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('Failed to save data: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Generate unique refund number
     */
    private function generateRefundNumber(TaxCase $taxCase): string
    {
        $count = RefundProcess::where('tax_case_id', $taxCase->id)->count() + 1;
        $fiscal = $taxCase->fiscal_year ?? date('Y');
        return sprintf('RFD-%s-%05d', $fiscal, $count);
    }
}
