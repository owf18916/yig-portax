<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\KianSubmission;
use App\Models\WorkflowHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KianSubmissionController extends ApiController
{
    /**
     * Store KIAN submission for a specific stage
     * Multiple KIAN per case concept (v2)
     * 
     * Endpoint: POST /api/tax-cases/{id}/kian-submissions/{stageId}
     */
    public function store(Request $request, TaxCase $taxCase, int $stageId = null)
    {
        try {
            // If stageId not in route, try to get from request
            if (!$stageId) {
                $stageId = $request->input('stage_id', 12);
            }

            // Validate stage_id is one of the allowed KIAN stages
            if (!in_array($stageId, [4, 7, 10, 12])) {
                return $this->error('Invalid stage ID. KIAN can only be submitted at stages 4, 7, 10, or 12', 422);
            }

            // ✅ NEW: Check if KIAN is needed at this stage
            if (!$taxCase->needsKianAtStage($stageId)) {
                return $this->error("KIAN is not needed at Stage {$stageId} for this tax case", 422);
            }

            // ✅ NEW: Check if KIAN for this stage already exists
            if (!$taxCase->canCreateKianForStage($stageId)) {
                $existingKian = $taxCase->kianSubmissions()->where('stage_id', $stageId)->first();
                return $this->error("KIAN for Stage {$stageId} already exists (ID: {$existingKian->id})", 422);
            }

            // ✅ NEW: Get pre-calculated loss amount for this stage
            $lossAmount = $taxCase->calculateLossAtStage($stageId);
            if ($lossAmount === null) {
                return $this->error("Unable to calculate loss amount for Stage {$stageId}", 422);
            }

            $validated = $request->validate([
                'kian_number' => 'required|string',
                'submission_date' => 'required|date',
                'notes' => 'nullable|string',
                'next_action' => 'nullable|string',
                'next_action_due_date' => 'nullable|date',
                'status_comment' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $kianSubmission = KianSubmission::create([
                'tax_case_id' => $taxCase->id,
                'stage_id' => $stageId,  // ✅ NEW: Store stage_id
                'kian_number' => $validated['kian_number'],
                'submission_date' => $validated['submission_date'],
                'loss_amount' => $lossAmount,  // ✅ NEW: Pre-fill loss amount from calculation
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
                'next_action' => $validated['next_action'] ?? null,
                'next_action_due_date' => $validated['next_action_due_date'] ?? null,
                'status_comment' => $validated['status_comment'] ?? null,
                'submitted_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_id' => $stageId,
                'action' => 'KIAN_DRAFT_CREATED',
                'description' => "KIAN draft created at Stage {$stageId} with loss amount: Rp " . number_format($lossAmount, 0, ',', '.'),
                'performed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            return $this->success($kianSubmission, "KIAN submission created for Stage {$stageId}", 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified KIAN submission
     */
    public function show(TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        if ($kianSubmission->tax_case_id !== $taxCase->id) {
            return $this->error('KIAN submission not found for this tax case', 404);
        }

        return $this->success($kianSubmission, 'KIAN submission retrieved');
    }

    /**
     * Submit KIAN submission (move from draft to submitted status)
     */
    public function submit(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        try {
            if ($kianSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('KIAN submission not found for this tax case', 404);
            }

            if ($kianSubmission->status !== 'draft') {
                return $this->error('Only draft KIAN submissions can be submitted', 400);
            }

            DB::beginTransaction();

            $kianSubmission->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by' => auth()->id(),
            ]);

            $stageId = $kianSubmission->stage_id ?? 12;

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_id' => $stageId,
                'action' => 'KIAN_SUBMITTED',
                'description' => "KIAN submitted at Stage {$stageId}",
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
            ]);

            DB::commit();

            return $this->success($kianSubmission, "KIAN for Stage {$stageId} submitted successfully", 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Record KIAN response from tax authority
     */
    public function recordResponse(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        try {
            if ($kianSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('KIAN submission not found for this tax case', 404);
            }

            $validated = $request->validate([
                'response_status' => 'required|in:ACCEPTED,REJECTED,PARTIALLY_ACCEPTED',
                'response_date' => 'required|date',
                'approved_amount' => 'nullable|numeric|min:0',
                'response_notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $kianSubmission->update([
                'status' => 'RESPONDED',
                'response_status' => $validated['response_status'],
                'response_date' => $validated['response_date'],
                'approved_amount' => $validated['approved_amount'] ?? null,
                'response_notes' => $validated['response_notes'] ?? null,
                'responded_date' => now(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 12,
                'action' => 'KIAN_RESPONSE_RECORDED',
                'description' => 'KIAN response recorded. Status: ' . $validated['response_status'],
                'performed_by' => auth()->id(),
                'notes' => $validated['response_notes'] ?? null,
            ]);

            // Update tax case status based on response
            $finalStatus = 'KIAN_' . $validated['response_status'];
            $taxCase->update([
                'current_stage' => 12,
                'status' => $finalStatus
            ]);

            DB::commit();

            return $this->success($kianSubmission, 'KIAN response recorded', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Close KIAN case
     */
    public function close(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        try {
            if ($kianSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('KIAN submission not found for this tax case', 404);
            }

            DB::beginTransaction();

            $kianSubmission->update([
                'status' => 'CLOSED',
                'closed_date' => now(),
                'closed_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 12,
                'action' => 'KIAN_CLOSED',
                'description' => 'KIAN case closed',
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
                'next_stage' => 13,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 13,
                'status' => 'CLOSED'
            ]);

            DB::commit();

            return $this->success($kianSubmission, 'KIAN case closed, tax case finalized', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
