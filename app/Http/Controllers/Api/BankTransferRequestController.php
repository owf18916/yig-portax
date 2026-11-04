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
     * @param int $refundId - Optional refund process ID (if not provided, uses latest)
     */
    public function show(Request $request, TaxCase $taxCase, $refundId = null): JsonResponse
    {
        try {
            // Get specific refund process or latest
            if ($refundId) {
                $refundProcess = $taxCase->refundProcesses()
                    ->where('id', $refundId)
                    ->with('bankTransferRequests')
                    ->first();
                
                if (!$refundProcess) {
                    return $this->error('Refund process not found or does not belong to this tax case', 404);
                }
            } else {
                // Fallback to latest for backward compatibility
                $refundProcess = $taxCase->refundProcesses()
                    ->with('bankTransferRequests')
                    ->latest()
                    ->first();

                if (!$refundProcess) {
                    return $this->error('No refund process found for this tax case', 404);
                }
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
     * @param int $refundId - Optional refund process ID (if not provided, creates new or uses latest)
     */
    public function createTransferRequest(Request $request, TaxCase $taxCase, $refundId = null): JsonResponse
    {
        return $this->error('Use explicit Refund stages 2-4; legacy payment writes are unsupported.', 422);
    }

    /**
     * Stage 14: Show transfer instruction data
     * @param int $refundId - Optional refund process ID (if not provided, uses latest)
     */
    public function showTransferInstruction(Request $request, TaxCase $taxCase, $refundId = null): JsonResponse
    {
        try {
            // Get specific refund process or latest
            if ($refundId) {
                $refundProcess = $taxCase->refundProcesses()
                    ->where('id', $refundId)
                    ->with('bankTransferRequests')
                    ->first();
                
                if (!$refundProcess) {
                    return $this->error('Refund process not found or does not belong to this tax case', 404);
                }
            } else {
                // Fallback to latest for backward compatibility
                $refundProcess = $taxCase->refundProcesses()
                    ->with('bankTransferRequests')
                    ->latest()
                    ->first();
            
                if (!$refundProcess) {
                    return $this->error('No refund process found', 404);
                }
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
     * @param int $refundId - Optional refund process ID (if not provided, uses latest)
     */
    public function updateTransferInstruction(Request $request, TaxCase $taxCase, $refundId = null): JsonResponse
    {
        return $this->error('Use explicit Refund stages 2-4; legacy payment writes are unsupported.', 422);
    }

    /**
     * Stage 15: Show refund receipt data
     * @param int $refundId - Optional refund process ID (if not provided, uses latest)
     */
    public function showRefundReceipt(Request $request, TaxCase $taxCase, $refundId = null): JsonResponse
    {
        try {
            // Get specific refund process or latest
            if ($refundId) {
                $refundProcess = $taxCase->refundProcesses()
                    ->where('id', $refundId)
                    ->with('bankTransferRequests')
                    ->first();
                
                if (!$refundProcess) {
                    return $this->error('Refund process not found or does not belong to this tax case', 404);
                }
            } else {
                // Fallback to latest for backward compatibility
                $refundProcess = $taxCase->refundProcesses()
                    ->with('bankTransferRequests')
                    ->latest()
                    ->first();
            
                if (!$refundProcess) {
                    return $this->error('No refund process found', 404);
                }
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
     * @param int $refundId - Optional refund process ID (if not provided, uses latest)
     */
    public function completeRefund(Request $request, TaxCase $taxCase, $refundId = null): JsonResponse
    {
        return $this->error('Use explicit Refund stages 2-4; legacy payment writes are unsupported.', 422);
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
