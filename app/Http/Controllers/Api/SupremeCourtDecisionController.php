<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\SupremeCourtDecision;
use App\Models\WorkflowHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupremeCourtDecisionController extends ApiController
{
    /**
     * Store supreme court decision (Stage 11b)
     */
    public function store(Request $request, TaxCase $taxCase)
    {
        try {
            $validated = $request->validate([
                'decision_number' => 'required|string|unique:supreme_court_decisions',
                'decision_date' => 'required|date',
                'decision_type' => 'required|in:GRANTED,REJECTED,PARTIALLY_GRANTED',
                'approved_amount' => 'required|numeric|min:0',
                'decision_reason' => 'required|string',
                'notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $scDecision = SupremeCourtDecision::create([
                'tax_case_id' => $taxCase->id,
                'decision_number' => $validated['decision_number'],
                'decision_date' => $validated['decision_date'],
                'decision_type' => $validated['decision_type'],
                'approved_amount' => $validated['approved_amount'],
                'decision_reason' => $validated['decision_reason'],
                'notes' => $validated['notes'] ?? null,
                'status' => 'DRAFT',
                'received_date' => now(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 11,
                'action' => 'SUPREME_COURT_DECISION_RECEIVED',
                'description' => 'Supreme court decision received',
                'performed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
                'decision_point' => 'supreme_court_decision',
                'decision_value' => $validated['decision_type'],
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 11,
                'status' => 'SUPREME_COURT_DECISION_RECEIVED'
            ]);

            DB::commit();

            return $this->success($scDecision, 'Supreme court decision created', 201);
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
