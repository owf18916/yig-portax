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
        app(\App\Services\KianDraftAccess::class)->authorize($taxCase);
        $initialLevel = DB::transactionLevel();
        try {
            // If stageId not in route, try to get from request
            if (! $stageId) {
                $stageId = filter_var($request->input('stage_id'), FILTER_VALIDATE_INT) ?: 0;
            }

            // Validate stage_id is one of the allowed KIAN stages
            if (! in_array($stageId, [4, 7, 10, 12])) {
                return $this->error('Invalid stage ID. KIAN can only be submitted at stages 4, 7, 10, or 12', 422);
            }

            // Lock the case before evaluating origin eligibility and upserting its draft.
            DB::beginTransaction();
            $taxCase = TaxCase::query()->lockForUpdate()->findOrFail($taxCase->id);
            if (! app(\App\Services\KianDraftAccess::class)->originEligible($taxCase, $stageId)) {
                return $this->error($stageId === 4 ? 'Stage 4 KIAN requirement is not confirmed.' : 'Submit the corresponding decision stage before creating Additional KIAN.', 422);
            }

            // ✅ NEW: Check if KIAN for this stage already exists
            $existingKian = $taxCase->kianSubmissions()->where('kian_submissions.stage_id', $stageId)->lockForUpdate()->first();

            // If KIAN exists but status is not 'draft', can't edit
            if ($existingKian && ! app(\App\Services\KianDraftAccess::class)->isDraft($existingKian)) {
                return $this->error("KIAN for Stage {$stageId} already exists with status '{$existingKian->status}'. Only draft submissions can be edited.", 422);
            }

            // Manual signed-document money; never derive it from exposure.
            $lossAmount = $this->manualAmount($request->all());
            $writeOffAmount = $request->exists('write_off_amount')
                ? $this->normalizeMoney($request->input('write_off_amount'), 'write_off_amount')
                : ($existingKian?->write_off_amount ?? '0.00');

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
            $request->validate(['action' => 'sometimes|in:save,draft,submit']);
            if ($action === 'submit') $this->requireSignedDocument($taxCase, $stageId, $existingKian?->id);

            // ✅ NEW: If KIAN exists and is draft, update it; otherwise create new
            if ($existingKian) {
                // Update existing draft KIAN
                $existingKian->update([
                    'loss_amount' => $lossAmount,
                    'write_off_amount' => $writeOffAmount,
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
                    'loss_amount' => $lossAmount,  // Same signed-document amount as kian_amount.
                    'status' => 'draft',
                    'write_off_amount' => $writeOffAmount,
                    'notes' => $validated['notes'] ?? null,
                    'next_action' => $validated['next_action'] ?? null,
                    'next_action_due_date' => $validated['next_action_due_date'] ?? null,
                    'status_comment' => $validated['status_comment'] ?? null,
                    'submitted_by' => auth()->id(),
                ]);
                $isUpdate = false;
            }

            // Reject lossy database storage (notably SQLite numeric affinity) rather than silently rounding.
            $kianSubmission->refresh();
            if ($kianSubmission->write_off_amount !== $writeOffAmount) {
                throw \Illuminate\Validation\ValidationException::withMessages(['write_off_amount' => 'The configured database cannot store this write-off amount exactly. No changes were saved.']);
            }
            if ($kianSubmission->kian_amount !== $lossAmount || $kianSubmission->loss_amount !== $lossAmount) {
                throw \Illuminate\Validation\ValidationException::withMessages(['loss_amount' => 'The configured database cannot store this KIAN amount exactly. No changes were saved.']);
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
            $taxCase->loadMissing('kianSubmissions');
            $kianSubmission = $taxCase->kianSubmissions->firstWhere('stage_id', $stageId);
            $actions = $kianSubmission && ! app(\App\Services\KianDraftAccess::class)->isDraft($kianSubmission)
                ? ['create' => false, 'save_draft' => false, 'submit' => false]
                : $taxCase->getKianStatusByStage()[$stageId]['draft_actions'];
            if (! $kianSubmission) {
                // No KIAN submission exists yet for this stage
                // Return null or empty so frontend knows it needs to be created
                return response()->json([
                    'success' => true, 'message' => 'No KIAN submission found for this stage', 'data' => null, 'draft_actions' => $actions,
                ]);
            }

            return response()->json(['success' => true, 'message' => 'KIAN submission retrieved for stage', 'data' => $this->representation($kianSubmission, $taxCase), 'draft_actions' => $actions]);
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
        app(\App\Services\KianDraftAccess::class)->authorize($taxCase);
        $initialLevel = DB::transactionLevel();
        try {
            DB::beginTransaction();
            $taxCase = TaxCase::query()->lockForUpdate()->findOrFail($taxCase->id);
            $kianSubmission = KianSubmission::query()->lockForUpdate()->findOrFail($kianSubmission->id);
            if ($kianSubmission->tax_case_id !== $taxCase->id) {
                return $this->error('KIAN submission not found for this tax case', 404);
            }

            if (! app(\App\Services\KianDraftAccess::class)->isDraft($kianSubmission)) {
                return $this->error('Only draft KIAN submissions can be submitted', 400);
            }

            if (! app(\App\Services\KianDraftAccess::class)->originEligible($taxCase, $kianSubmission->stage_id)) {
                return $this->error('KIAN origin is not eligible. Optional origins require a submitted decision stage.', 422);
            }
            // Finalize the saved signed-document amount; submit never replaces it from a request or exposure.
            $amount = $this->manualAmount(['kian_amount' => $kianSubmission->kian_amount]);
            $kianSubmission->loss_amount = $amount;
            $this->requireSignedDocument($taxCase, $kianSubmission->stage_id, $kianSubmission->id);

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

            if ($e instanceof \Illuminate\Validation\ValidationException) throw $e;
            return $this->error($e->getMessage(), 500);
        } finally {
            if (DB::transactionLevel() > $initialLevel) DB::rollBack();
        }
    }

    private function manualAmount(array $input): string
    {
        $normalize = fn ($value) => $this->normalizeMoney($value, 'loss_amount');
        $amount = $normalize($input['loss_amount'] ?? $input['kian_amount'] ?? null);
        if (isset($input['loss_amount'], $input['kian_amount']) && $amount !== $normalize($input['kian_amount'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['loss_amount' => 'KIAN amount fields must identify the same signed-document amount.']);
        }
        return $amount;
    }

    private function normalizeMoney($value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => 'Enter a nonnegative decimal string with at most two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > 18) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => 'The amount exceeds the stored money field capacity (18 integer digits).']);
        }
        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private function requireSignedDocument(TaxCase $case, int $stage, ?int $id): void
    {
        // The active StageForm uploads stage-scoped documents before the first draft (id 0).
        $exists = $case->documents()->where('documentable_type', KianSubmission::class)
            ->where('stage_code', (string) $stage)->whereIn('documentable_id', [0, $id ?? 0])
            ->where('document_type', 'supporting_document')->exists();
        if (! $exists) throw \Illuminate\Validation\ValidationException::withMessages(['document' => 'Upload the signed internal KIAN document before submitting.']);
    }

    /**
     * Retired KIAN response command (authorized historical reads remain available)
     */
    public function recordResponse(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        return $this->lifecycleCommand(fn () => app(\App\Services\KianLifecycle::class)->record($taxCase->id, $kianSubmission->id, $request->all(), $request->file('document')), $taxCase);
    }

    /**
     * Retired KIAN closure command
     */
    public function close(Request $request, TaxCase $taxCase, KianSubmission $kianSubmission)
    {
        return $this->lifecycleCommand(fn () => app(\App\Services\KianLifecycle::class)->close($taxCase->id, $kianSubmission->id), $taxCase);
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
        app(\App\Services\KianDraftAccess::class)->authorize($taxCase);
        if (!in_array($stageId, [4, 7, 10, 12], true)) return $this->error('Invalid KIAN stage.', 422);
        if (!$taxCase->kianSubmissions()->where('stage_id', $stageId)->exists()) return $this->error('No KIAN submission found for this stage.', 404);
        $request->merge(['action' => 'save']);
        return $this->store($request, $taxCase, $stageId);
    }
}
