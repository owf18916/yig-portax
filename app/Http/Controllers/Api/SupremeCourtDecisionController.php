<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\SupremeCourtDecision;
use App\Models\WorkflowHistory;
use App\Jobs\SendKianReminderJob;
use App\Services\DecisionPointRefundService;
use App\Services\KianNotificationService;
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
            if ($taxCase->current_stage !== 12) {
                return $this->error('Tax case is not at Supreme Court Decision stage', 422);
            }

            $validated = $request->validate([
                'decision_number' => 'required|string|unique:supreme_court_decisions',
                'decision_date' => 'required|date',
                'decision_type' => 'required|in:granted,partially_granted,rejected',
                'decision_amount' => 'required|numeric|min:0',
                'decision_notes' => 'nullable|string',
                'notes' => 'nullable|string',
                'create_refund' => 'nullable|boolean',
                'refund_amount' => 'nullable|numeric|min:0',
            ]);

            // ⭐ CHANGE 3: Validate independent actions
            $validated['create_refund'] = $request->boolean('create_refund');
            $validated['refund_amount'] = $validated['refund_amount'] ?? null;

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

            app(DecisionPointRefundService::class)->createIfRequested($scDecision, 12, auth()->id());

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_id' => 12,
                'stage_from' => 12,
                'action' => 'submitted',
                'status' => 'submitted',
                'user_id' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
                'decision_point' => 'independent_actions',
                'decision_value' => json_encode([
                    'decision_type' => $validated['decision_type'],
                    'create_refund' => $validated['create_refund'],
                    'refund_amount' => $validated['refund_amount'],
                ]),
            ]);

            app(KianNotificationService::class)->evaluateFinalSubmit($taxCase->fresh(), 12);

            // Mark case as completed (Supreme Court is final stage)
            $taxCase->update([
                'current_stage' => 12,
                'is_completed' => true,
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
    public function show(TaxCase $taxCase)
    {
        $supremeCourtDecision = $taxCase->supremeCourtDecision;
        if (! $supremeCourtDecision) {
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

            if ($supremeCourtDecision->status !== 'draft') {
                return $this->error('Only draft decisions can be approved', 400);
            }

            DB::beginTransaction();

            $supremeCourtDecision->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            $decisionType = $supremeCourtDecision->decision_type;

            // Log workflow history with decision routing
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_id' => 12,
                'stage_from' => 12,
                'action' => 'approved',
                'status' => 'approved',
                'user_id' => auth()->id(),
                'notes' => $request->input('notes'),
                'decision_point' => 'supreme_court_decision',
                'decision_value' => $decisionType,
            ]);

            $taxCase->update([
                'current_stage' => 12,
                'is_completed' => true,
            ]);

            DB::commit();

            return $this->success($supremeCourtDecision, 'Supreme court decision approved. Main workflow remains terminal at Stage 12.', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
