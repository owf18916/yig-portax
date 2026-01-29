<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\SupremeCourtDecision;
use App\Models\WorkflowHistory;
use App\Jobs\SendKianReminderJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupremeCourtDecisionController extends ApiController
{
    /**
     * Store supreme court decision (Stage 12)
     */
    public function store(Request $request, TaxCase $taxCase)
    {
        try {
            $validated = $request->validate([
                'decision_number' => 'required|string|unique:supreme_court_decisions',
                'decision_date' => 'required|date',
                'decision_type' => 'required|in:GRANTED,REJECTED,PARTIALLY_GRANTED',
                'decision_amount' => 'required|numeric|min:0',
                'decision_notes' => 'nullable|string',
                'notes' => 'nullable|string',
                'create_refund' => 'nullable|boolean',
                'refund_amount' => 'nullable|numeric|min:0',
            ]);

            // ⭐ CHANGE 3: Validate independent actions
            if ($validated['create_refund']) {
                $validated['refund_amount'] = $request->input('refund_amount');
                if (!$validated['refund_amount'] || $validated['refund_amount'] <= 0) {
                    return $this->error('Refund amount must be greater than 0 when creating refund', 422);
                }
                
                // Validate refund amount doesn't exceed available amount
                $availableAmount = max(0, $taxCase->disputed_amount - ($taxCase->getTotalRefundedAmount() ?? 0));
                if ($validated['refund_amount'] > $availableAmount) {
                    return $this->error("Refund amount cannot exceed available amount (Rp {$availableAmount})", 422);
                }
            }

            DB::beginTransaction();

            $scDecision = SupremeCourtDecision::create([
                'tax_case_id' => $taxCase->id,
                'decision_number' => $validated['decision_number'],
                'decision_date' => $validated['decision_date'],
                'decision_type' => $validated['decision_type'],
                'decision_amount' => $validated['decision_amount'],
                'decision_notes' => $validated['decision_notes'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'create_refund' => $validated['create_refund'] ?? false,
                'refund_amount' => $validated['refund_amount'] ?? null,
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
                'status' => 'submitted',
            ]);

            // ⭐ CHANGE 3: Create refund if requested
            if ($validated['create_refund']) {
                $scDecision->createRefundIfNeeded();
            }

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_id' => 12,
                'stage_from' => 12,
                'stage_number' => 12,
                'action' => 'SUPREME_COURT_DECISION_SUBMITTED',
                'description' => 'Supreme court decision submitted',
                'performed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
                'decision_point' => 'independent_actions',
                'decision_value' => json_encode([
                    'decision_type' => $validated['decision_type'],
                    'create_refund' => $validated['create_refund'],
                    'refund_amount' => $validated['refund_amount'],
                ]),
            ]);

            // ⭐ CHANGE 4: Check if KIAN reminder email should be sent
            // Supreme Court is final stage, so if decision is REJECTED/PARTIALLY_GRANTED and no refund was created,
            // KIAN reminder should be sent
            if (!$validated['create_refund'] && $validated['decision_type'] !== 'GRANTED') {
                $reason = $taxCase->getKianEligibilityReason();
                if ($reason) {
                    dispatch(new SendKianReminderJob($taxCase, 'Supreme Court Decision (Stage 12)', $reason));
                }
            }

            // Mark case as completed (Supreme Court is final stage)
            $taxCase->update([
                'current_stage' => null,
                'is_completed' => true,
                'status' => 'COMPLETED'
            ]);

            DB::commit();

            return $this->success($scDecision, 'Supreme court decision submitted', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified supreme court decision
     */
    public function show(TaxCase $taxCase, SupremeCourtDecision $supremeCourtDecision)
    {
        if ($supremeCourtDecision->tax_case_id !== $taxCase->id) {
            return $this->error('Supreme court decision not found for this tax case', 404);
        }

        return $this->success($supremeCourtDecision, 'Supreme court decision retrieved');
    }

    /**
     * Approve supreme court decision with automatic routing
     * DECISION ROUTING:
     * - GRANTED/PARTIALLY_GRANTED → Stage 12 (Refund Process)
     * - REJECTED → Case Closed
     */
    public function approve(Request $request, TaxCase $taxCase, SupremeCourtDecision $supremeCourtDecision)
    {
        try {
            if ($supremeCourtDecision->tax_case_id !== $taxCase->id) {
                return $this->error('Supreme court decision not found for this tax case', 404);
            }

            if ($supremeCourtDecision->status !== 'DRAFT') {
                return $this->error('Only draft decisions can be approved', 400);
            }

            DB::beginTransaction();

            $supremeCourtDecision->update([
                'status' => 'APPROVED',
                'approved_date' => now(),
                'approved_by' => auth()->id(),
            ]);

            // Determine next stage based on decision type
            $decisionType = $supremeCourtDecision->decision_type;
            $nextStage = ($decisionType === 'REJECTED') ? 13 : 12;
            $nextStatus = ($decisionType === 'REJECTED') ? 'CLOSED' : 'REFUND_PROCESSING';

            // Log workflow history with decision routing
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 11,
                'action' => 'SUPREME_COURT_DECISION_APPROVED',
                'description' => 'Supreme court decision approved. Decision: ' . $decisionType,
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
                'decision_point' => 'supreme_court_decision',
                'decision_value' => $decisionType,
                'next_stage' => $nextStage,
            ]);

            // Update tax case status with automatic routing
            $taxCase->update([
                'current_stage' => $nextStage,
                'status' => $nextStatus
            ]);

            DB::commit();

            $message = ($decisionType === 'REJECTED')
                ? 'Supreme court decision approved. Case closed with rejection.'
                : 'Supreme court decision approved. Proceeding to Stage 12 (Refund Process).';

            return $this->success($supremeCourtDecision, $message, 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
