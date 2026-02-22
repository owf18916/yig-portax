<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\KianSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KianSubmissionController extends ApiController
{
    /**
     * Store KIAN submission for a specific stage (create or update if already exists)
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
            $existingKian = $taxCase->kianSubmissions()->where('kian_submissions.stage_id', $stageId)->first();
            
            // If KIAN exists but status is not 'draft', can't edit  
            if ($existingKian && $existingKian->status !== 'draft') {
                return $this->error("KIAN for Stage {$stageId} already exists with status '{$existingKian->status}'. Only draft submissions can be edited.", 422);
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

            // ✅ NEW: Check action from request - can be 'save' (draft) or 'submit'
            $action = $request->input('action', 'save');

            DB::beginTransaction();

            // ✅ NEW: If KIAN exists and is draft, update it; otherwise create new
            if ($existingKian) {
                // Update existing draft KIAN
                $existingKian->update([
                    'kian_number' => $validated['kian_number'],
                    'submission_date' => $validated['submission_date'],
                    'notes' => $validated['notes'] ?? null,
                    'next_action' => $validated['next_action'] ?? null,
                    'next_action_due_date' => $validated['next_action_due_date'] ?? null,
                    'status_comment' => $validated['status_comment'] ?? null,
                ]);
                $kianSubmission = $existingKian;
                $isUpdate = true;
            } else {
                // Create new KIAN
                $kianSubmission = KianSubmission::create([
                    'tax_case_id' => $taxCase->id,
                    'stage_id' => $stageId,  // ✅ NEW: Store stage_id
                    'kian_number' => $validated['kian_number'],
                    'submission_date' => $validated['submission_date'],
                    'kian_amount' => $lossAmount,  // Amount requested for KIAN
                    'loss_amount' => $lossAmount,  // ✅ NEW: Pre-fill loss amount from calculation
                    'status' => 'draft',
                    'notes' => $validated['notes'] ?? null,
                    'next_action' => $validated['next_action'] ?? null,
                    'next_action_due_date' => $validated['next_action_due_date'] ?? null,
                    'status_comment' => $validated['status_comment'] ?? null,
                    'submitted_by' => auth()->id(),
                ]);
                $isUpdate = false;
            }

            // ✅ NEW: If action='submit', transition status from draft → submitted
            if ($action === 'submit' && $kianSubmission->status === 'draft') {
                $kianSubmission->update([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]);
                $message = $isUpdate ? "KIAN submission updated and submitted for Stage {$stageId}" : "KIAN submission created and submitted for Stage {$stageId}";
            } else {
                $message = $isUpdate ? "KIAN submission updated for Stage {$stageId}" : "KIAN submission created for Stage {$stageId}";
            }

            DB::commit();

            return $this->success($kianSubmission, $message, $isUpdate ? 200 : 201);
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
     * ✅ NEW: Display KIAN submission for a specific stage
     * Endpoint: GET /api/tax-cases/{id}/kian-submissions/{stageId}
     */
    public function showByStage(TaxCase $taxCase, int $stageId)
    {
        try {
            // Validate stage is a valid KIAN stage
            if (!in_array($stageId, [4, 7, 10, 12])) {
                return $this->error('Invalid stage ID. KIAN can only be retrieved at stages 4, 7, 10, or 12', 422);
            }

            // Find existing KIAN submission for this stage
            $kianSubmission = $taxCase->kianSubmissions()
                ->where('kian_submissions.stage_id', $stageId)
                ->first();

            if (!$kianSubmission) {
                // No KIAN submission exists yet for this stage
                // Return null or empty so frontend knows it needs to be created
                return $this->success(null, 'No KIAN submission found for this stage (new submission will be created)', 200);
            }

            return $this->success($kianSubmission, 'KIAN submission retrieved for stage', 200);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
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

    /**
     * ✅ NEW: Update existing draft KIAN submission for a specific stage
     * Endpoint: PUT /api/tax-cases/{id}/kian-submissions/{stageId}
     */
    public function updateByStage(Request $request, TaxCase $taxCase, int $stageId)
    {
        try {
            // Validate stage is a valid KIAN stage
            if (!in_array($stageId, [4, 7, 10, 12])) {
                return $this->error('Invalid stage ID. KIAN can only be updated at stages 4, 7, 10, or 12', 422);
            }

            // Find existing KIAN submission for this stage
            $kianSubmission = $taxCase->kianSubmissions()
                ->where('kian_submissions.stage_id', $stageId)
                ->first();

            if (!$kianSubmission) {
                return $this->error("No KIAN submission found for Stage {$stageId} to update", 404);
            }

            // Only allow updating draft submissions
            if ($kianSubmission->status !== 'draft') {
                return $this->error("Cannot update KIAN submission with status '{$kianSubmission->status}'. Only draft submissions can be edited.", 400);
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

            // Update the KIAN submission
            $kianSubmission->update([
                'kian_number' => $validated['kian_number'],
                'submission_date' => $validated['submission_date'],
                'notes' => $validated['notes'] ?? null,
                'next_action' => $validated['next_action'] ?? null,
                'next_action_due_date' => $validated['next_action_due_date'] ?? null,
                'status_comment' => $validated['status_comment'] ?? null,
            ]);

            DB::commit();

            return $this->success($kianSubmission, "KIAN submission updated for Stage {$stageId}", 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
