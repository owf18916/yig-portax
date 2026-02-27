<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\SkpRecord;
use App\Jobs\SendKianReminderJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class SkpRecordController extends ApiController
{
    /**
     * Store SKP record (Stage 4)
     * DECISION POINT: User's explicit choice (user_routing_choice) determines next stage
     * NOT based on skp_type - user has explicit control
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Validate stage
        if ($taxCase->current_stage !== 4) {
            return $this->error('Tax case is not at SKP stage', 422);
        }

        $validated = $request->validate([
            'skp_number' => 'required|string|unique:skp_records',
            'issue_date' => 'required|date',
            'receipt_date' => 'nullable|date',
            'skp_type' => 'required|in:LB,NIHIL,KB',
            'skp_amount' => 'required|numeric|min:0',
            'royalty_correction' => 'nullable|numeric|min:0',
            'service_correction' => 'nullable|numeric|min:0',
            'other_correction' => 'nullable|numeric|min:0',
            'correction_notes' => 'nullable|string',
            'user_routing_choice' => 'required|in:refund,objection',
            'create_refund' => 'nullable|boolean',
            'refund_amount' => 'nullable|numeric|min:0',
            'continue_to_next_stage' => 'nullable|boolean',
        ]);

        // ⭐ ENSURE BOOLEANS ARE ACTUAL BOOLEANS (not strings or numbers)
        $validated['create_refund'] = filter_var($validated['create_refund'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $validated['continue_to_next_stage'] = filter_var($validated['continue_to_next_stage'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        // ⭐ DEBUG: Log values BEFORE saving
        Log::info('[SKP] VALUES BEFORE SAVE', [
            'create_refund_value' => $validated['create_refund'],
            'create_refund_type' => gettype($validated['create_refund']),
            'continue_to_next_stage_value' => $validated['continue_to_next_stage'],
            'continue_to_next_stage_type' => gettype($validated['continue_to_next_stage']),
        ]);

        // ⭐ ENSURE BOOLEANS ARE ACTUAL BOOLEANS (convert all to bool explicitly)
        // PHP's filter_var is reliable, but let's also check raw input
        $rawCreateRefund = $request->input('create_refund');
        $rawContinueToNextStage = $request->input('continue_to_next_stage');
        
        Log::info('[SKP] RAW INPUT FROM REQUEST', [
            'raw_create_refund' => $rawCreateRefund,
            'raw_create_refund_type' => gettype($rawCreateRefund),
            'raw_continue_to_next_stage' => $rawContinueToNextStage,
            'raw_continue_to_next_stage_type' => gettype($rawContinueToNextStage),
        ]);

        // Explicit boolean conversion
        $validated['create_refund'] = filter_var($validated['create_refund'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $validated['continue_to_next_stage'] = filter_var($validated['continue_to_next_stage'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        // ⭐ CHANGE 3: Validate independent actions
        if ($validated['create_refund']) {
            $validated['refund_amount'] = $request->input('refund_amount');
            if (!$validated['refund_amount'] || $validated['refund_amount'] <= 0) {
                return $this->error('Refund amount must be greater than 0 when creating refund', 422);
            }
            
            // Validate refund amount doesn't exceed available amount
            $availableAmount = max(0, $taxCase->disputed_amount - $validated['skp_amount']);
            if ($validated['refund_amount'] > $availableAmount) {
                return $this->error("Refund amount cannot exceed available amount (Rp {$availableAmount})", 422);
            }
        }

        $validated['tax_case_id'] = $taxCase->id;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();
        $validated['status'] = 'submitted';

        // Determine next stage based on USER'S CHOICE, NOT skp_type
        $nextStageId = $this->determineNextStageFromUserChoice($validated['user_routing_choice']);
        
        // ⭐ CHANGE 3: Override next stage if continue_to_next_stage is false
        if (!$validated['continue_to_next_stage']) {
            $nextStageId = null; // Case will end
        }
        
        $validated['next_stage_id'] = $nextStageId;

        $skpRecord = SkpRecord::create($validated);

        // ⭐ DEBUG: Log values AFTER saving
        Log::info('[SKP] VALUES AFTER SAVE', [
            'create_refund_db' => $skpRecord->create_refund,
            'create_refund_type' => gettype($skpRecord->create_refund),
            'continue_to_next_stage_db' => $skpRecord->continue_to_next_stage,
            'continue_to_next_stage_type' => gettype($skpRecord->continue_to_next_stage),
            'raw_attributes' => [
                'create_refund' => $skpRecord->getAttributes()['create_refund'] ?? 'NOT SET',
                'continue_to_next_stage' => $skpRecord->getAttributes()['continue_to_next_stage'] ?? 'NOT SET',
            ]
        ]);

        // ⭐ CHANGE 3: Create refund if requested
        if ($validated['create_refund']) {
            $skpRecord->createRefundIfNeeded();
        }

        // Update tax case with next stage (or null if case ends)
        if ($validated['continue_to_next_stage']) {
            $taxCase->update([
                'current_stage' => $nextStageId,
            ]);
        }

        // Log workflow with user's decision
        $taxCase->workflowHistories()->create([
            'stage_id' => 4,
            'stage_from' => 4,
            'stage_to' => $nextStageId,
            'action' => 'submitted',
            'decision_point' => 'independent_actions',
            'decision_value' => json_encode([
                'create_refund' => $validated['create_refund'],
                'refund_amount' => $validated['refund_amount'],
                'continue_to_next_stage' => $validated['continue_to_next_stage'],
            ]),
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        // ✅ FIXED: Check if KIAN reminder email should be sent
        // KIAN is needed WHENEVER loss exists at Stage 4, REGARDLESS of next stage choice
        Log::info('[SKP] KIAN CHECK - Checking eligibility at Stage 4...');
        
        if ($taxCase->needsKianAtStage(4)) {
            $reason = $taxCase->getKianEligibilityReasonForStage(4);
            
            Log::info('[SKP] KIAN CHECK - Stage 4 needs KIAN', [
                'reason' => $reason,
                'loss_amount' => $taxCase->calculateLossAtStage(4),
                'tax_case_id' => $taxCase->id,
            ]);
            
            // ✅ UPDATED: Dispatch with stage_id = 4
            $caseId = (int) $taxCase->id;
            dispatch(new SendKianReminderJob($caseId, 'Stage 4 - SKP (Surat Ketetapan Pajak)', $reason, 4));
            
            Log::info('[SKP] KIAN CHECK - Job dispatched successfully for Stage 4');
        } else {
            Log::info('[SKP] KIAN CHECK - Stage 4 does not need KIAN (no loss detected)');
        }

        return $this->success(
            $skpRecord->load(['taxCase', 'submittedBy']),
            'SKP record submitted successfully',
            201
        );
    }

    /**
     * Approve SKP record and route to next stage based on user's choice
     */
    public function approve(Request $request, TaxCase $taxCase, SkpRecord $skpRecord): JsonResponse
    {
        if ($skpRecord->tax_case_id !== $taxCase->id) {
            return $this->error('SKP record does not belong to this tax case', 422);
        }

        if ($skpRecord->status === 'approved') {
            return $this->error('SKP record already approved', 422);
        }

        $validated = $request->validate([
            'correction_notes' => 'nullable|string',
        ]);

        $skpRecord->update([
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'status' => 'approved',
            'correction_notes' => $validated['correction_notes'] ?? $skpRecord->correction_notes,
        ]);

        // Next stage determined by user's routing choice, NOT skp_type
        $nextStageId = $skpRecord->next_stage_id ?? $this->determineNextStageFromUserChoice($skpRecord->user_routing_choice);
        $taxCase->update([
            'next_stage_id' => $nextStageId,
        ]);

        // Log workflow
        $taxCase->workflowHistories()->create([
            'stage_from' => 4,
            'stage_to' => $nextStageId,
            'action' => 'approved',
            'decision_point' => 'user_routing_choice',
            'decision_value' => $skpRecord->user_routing_choice,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        $stageName = $nextStageId === 5 ? 'Objection (Stage 5)' : 'Refund (Stage 13)';

        return $this->success(
            $skpRecord->fresh(['taxCase', 'approvedBy']),
            "SKP record approved and case routed to {$stageName}"
        );
    }

    /**
     * Get SKP record for a tax case
     */
    public function show(TaxCase $taxCase): JsonResponse
    {
        $skpRecord = $taxCase->skpRecord()->with(['submittedBy', 'approvedBy'])->first();

        if (!$skpRecord) {
            return $this->error('No SKP record found for this tax case', 404);
        }

        return $this->success($skpRecord);
    }

    /**
     * Determine next stage based on user's explicit choice (NOT skp_type)
     * 'refund' → Stage 13 (Bank Transfer Request)
     * 'objection' → Stage 5 (Surat Keberatan)
     */
    private function determineNextStageFromUserChoice(string $userChoice): int
    {
        return match($userChoice) {
            'refund' => 13,       // Bank Transfer Request
            'objection' => 5,     // Surat Keberatan (Objection)
            default => 5,         // Default to Objection if invalid
        };
    }
}
