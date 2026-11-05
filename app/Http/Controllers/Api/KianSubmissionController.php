<?php

namespace App\Http\Controllers\Api;

use App\Models\KianSubmission;
use App\Models\TaxCase;
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
    public function store(Request $request, TaxCase $taxCase, ?int $stageId = null)
    {
        $initialLevel = DB::transactionLevel();
        try {
            // If stageId not in route, try to get from request
            if (! $stageId) {
                $stageId = $request->input('stage_id', 12);
            }

            // Validate stage_id is one of the allowed KIAN stages
            if (! in_array($stageId, [4, 7, 10, 12])) {
                return $this->error('Invalid stage ID. KIAN can only be submitted at stages 4, 7, 10, or 12', 422);
            }

            // ✅ NEW: Check if KIAN is needed at this stage
            DB::beginTransaction();
            $taxCase = TaxCase::query()->lockForUpdate()->findOrFail($taxCase->id);
            $evaluation = $taxCase->exposureByStage()[$stageId];
            if ($evaluation['evaluationState'] !== 'resolved') {
                return $this->error('KIAN exposure is unresolved: '.($evaluation['evaluationReason'] ?? 'Decision Point is pending.'), 422);
            }
            if ($evaluation['needsKian'] !== true) {
                return $this->error("KIAN is not needed at Stage {$stageId} for this tax case", 422);
            }

            // ✅ NEW: Check if KIAN for this stage already exists
            $existingKian = $taxCase->kianSubmissions()->where('kian_submissions.stage_id', $stageId)->lockForUpdate()->first();

            // If KIAN exists but status is not 'draft', can't edit
            if ($existingKian && $existingKian->status !== 'draft') {
                return $this->error("KIAN for Stage {$stageId} already exists with status '{$existingKian->status}'. Only draft submissions can be edited.", 422);
            }

            // ✅ NEW: Get pre-calculated loss amount for this stage
            $lossAmount = $evaluation['remainingExposure'];
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

            // ✅ NEW: If KIAN exists and is draft, update it; otherwise create new
            if ($existingKian) {
                // Update existing draft KIAN
                $existingKian->update([
                    'loss_amount' => $lossAmount,
                    'kian_amount' => $lossAmount,
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
            if (DB::transactionLevel() > $initialLevel) DB::rollBack();

            if ($e instanceof \Illuminate\Validation\ValidationException) throw $e;
            return $this->error($e->getMessage(), 500);
        } finally {
            if (DB::transactionLevel() > $initialLevel) DB::rollBack();
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
            if (! in_array($stageId, [4, 7, 10, 12])) {
                return $this->error('Invalid stage ID. KIAN can only be retrieved at stages 4, 7, 10, or 12', 422);
            }

            // Find existing KIAN submission for this stage
            $kianSubmission = $taxCase->kianSubmissions()
                ->where('kian_submissions.stage_id', $stageId)
                ->first();

            if (! $kianSubmission) {
                // No KIAN submission exists yet for this stage
                // Return null or empty so frontend knows it needs to be created
                return $this->success(null, 'No KIAN submission found for this stage (new submission will be created)', 200);
            }

            return $this->success($this->representation($kianSubmission, $taxCase), 'KIAN submission retrieved for stage', 200);
        } catch (\Exception $e) {
            report($e);
            return $this->error('Unable to retrieve KIAN submission.', 500);
        }
    }

    /**
     * Submit KIAN submission (move from draft to submitted status)
     */
    public function submit(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        $initialLevel = DB::transactionLevel();
        try {
            DB::beginTransaction();
            $taxCase = TaxCase::query()->lockForUpdate()->findOrFail($taxCase->id);
            $kianSubmission = KianSubmission::query()->lockForUpdate()->findOrFail($kianSubmission->id);
            if ($kianSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('KIAN submission not found for this tax case', 404);
            }

            if ($kianSubmission->status !== 'draft') {
                return $this->error('Only draft KIAN submissions can be submitted', 400);
            }

            $evaluation = $taxCase->exposureByStage()[$kianSubmission->stage_id ?? 12];
            if ($evaluation['needsKian'] !== true) {
                return $this->error('KIAN requires resolved positive remaining exposure.', 422);
            }
            $kianSubmission->loss_amount = $evaluation['remainingExposure'];
            $kianSubmission->kian_amount = $evaluation['remainingExposure'];

            $kianSubmission->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by' => auth()->id(),
            ]);

            $stageId = $kianSubmission->stage_id ?? 12;

            DB::commit();

            return $this->success($kianSubmission, "KIAN for Stage {$stageId} submitted successfully", 200);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > $initialLevel) DB::rollBack();

            return $this->error($e->getMessage(), 500);
        } finally {
            if (DB::transactionLevel() > $initialLevel) DB::rollBack();
        }
    }

    /**
     * Record KIAN response from tax authority
     */
    public function recordResponse(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        $this->rejectClientProvenance($request);
        return $this->lifecycleCommand(fn () => app(\App\Services\KianLifecycle::class)->record($taxCase->id, $kianSubmission->id, $request->all(), $request->file('document')), $taxCase);
    }

    /**
     * Close KIAN case
     */
    public function close(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        $request->validate(array_fill_keys(array_merge(\App\Models\KianSubmission::FINAL_FIELDS, ['document', 'status', 'closed_date', 'closer', 'recorded_by', 'recorded_at']), 'prohibited'));
        return $this->lifecycleCommand(fn () => app(\App\Services\KianLifecycle::class)->close($taxCase->id, $kianSubmission->id), $taxCase);
    }

    private function rejectClientProvenance(Request $request): void
    {
        $request->validate(array_fill_keys(['responded_by', 'responded_at', 'recorded_by', 'recorded_at', 'closed_by', 'closed_at', 'response_document_id', 'document_id', 'documentable_id', 'documentable_type', 'tax_case_id', 'stage_id', 'stage_source', 'status', 'uploaded_by', 'uploaded_at', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at'], 'prohibited'));
    }

    private function lifecycleCommand(callable $operation, TaxCase $case)
    {
        try {
            return $this->success($this->representation($operation(), $case), 'KIAN lifecycle recorded');
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Auth\Access\AuthorizationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            return $this->error('Unable to complete the KIAN request. Retry the identical command to confirm its recorded state.', 500);
        }
    }

    private function representation(KianSubmission $kian, TaxCase $case): array
    {
        $kian->loadMissing(['respondedBy:id,name', 'closedBy:id,name', 'responseDocument']);
        $data = $kian->toArray();
        $data['available_actions'] = app(\App\Services\KianLifecycle::class)->availableActions($kian, $case);
        $data['final_response'] = $kian->response_status === null ? null : [
            'outcome' => $kian->response_status, 'reference' => $kian->response_reference,
            'issued_date' => $kian->response_issued_date?->toDateString(), 'received_date' => $kian->response_received_date?->toDateString(),
            'approved_amount' => $kian->approved_amount, 'notes' => $kian->response_notes,
            'recorded_by' => $kian->respondedBy?->only(['id', 'name']), 'recorded_at' => $kian->responded_at?->toIso8601String(),
            'document' => $kian->responseDocument ? $kian->responseDocument->only(['id', 'original_filename', 'file_size', 'file_mime_type']) + ['download_url' => '/api/documents/'.$kian->response_document_id.'/download'] : null,
        ];
        $data['closure'] = $kian->closed_at === null ? null : ['closed_by' => $kian->closedBy?->only(['id', 'name']), 'closed_at' => $kian->closed_at->toIso8601String()];
        return $data;
    }

    /**
     * ✅ NEW: Update existing draft KIAN submission for a specific stage
     * Endpoint: PUT /api/tax-cases/{id}/kian-submissions/{stageId}
     */
    public function updateByStage(Request $request, TaxCase $taxCase, int $stageId)
    {
        if (!in_array($stageId, [4, 7, 10, 12], true)) return $this->error('Invalid KIAN stage.', 422);
        if (!$taxCase->kianSubmissions()->where('stage_id', $stageId)->exists()) return $this->error('No KIAN submission found for this stage.', 404);
        $request->merge(['action' => 'save']);
        return $this->store($request, $taxCase, $stageId);
    }
}
