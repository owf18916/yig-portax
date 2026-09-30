<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\Revision;
use App\Models\Document;
use App\Services\RevisionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RevisionController extends Controller
{
    use AuthorizesRequests;

    private RevisionService $revisionService;

    public function __construct(RevisionService $revisionService)
    {
        $this->revisionService = $revisionService;
    }

    /**
     * Request a revision for SPT Filling (TaxCase)
     * NEW FLOW: User proposes values + document changes + files in one request
     * Files are uploaded with FormData
     * 
     * POST /api/tax-cases/{caseId}/revisions/request
     */
    public function requestRevision(Request $request, TaxCase $taxCase): JsonResponse
    {
        $user = auth()->user();
        
        // Ensure entity is loaded for authorization
        $user->load('entity');
        
        // Create a dummy Revision instance for policy check
        $dummyRevision = new Revision();
        $this->authorize('request', [$dummyRevision, $taxCase]);

        // Validate FormData files
        $request->validate([
            'files' => 'nullable|array',
            'files.*' => 'file|mimes:pdf|max:10240', // Max 10MB per file
        ]);

        // Accept multipart (with uploads) and JSON clients through one contract.
        $payloadJson = $request->input('payload');
        $payload = $payloadJson ? json_decode($payloadJson, true) : $request->all();

        if (!$payload || !is_array($payload)) {
            return response()->json([
                'error' => 'Invalid payload format',
            ], 422);
        }

        // Validate payload data manually
        if (empty($payload['fields']) || !is_array($payload['fields'])) {
            return response()->json([
                'error' => 'Fields are required',
            ], 422);
        }

        if (empty($payload['reason']) || strlen($payload['reason']) < 10) {
            return response()->json([
                'error' => 'Reason is required and must be at least 10 characters',
            ], 422);
        }

        if (!isset($payload['proposed_document_changes'])) {
            return response()->json([
                'error' => 'Document changes structure is required',
            ], 422);
        }

        $stageCode = (int) ($payload['stage_code'] ?? 0);
        $this->ensureDocumentsBelongToTaxCase(
            array_merge(
                $payload['proposed_document_changes']['files_to_delete'] ?? [],
                $payload['proposed_document_changes']['files_to_add'] ?? []
            ),
            $taxCase,
            $stageCode
        );
        if ($stageCode < 1 || $stageCode > 12) {
            return response()->json(['error' => 'stage_code must be a canonical main stage from 1 through 12.'], 422);
        }

        // Custom validation: ensure at least one change is proposed
        $hasFieldChanges = collect($payload['proposed_values'] ?? [])->isNotEmpty();

        $hasDocumentChanges = (
            collect($payload['proposed_document_changes']['files_to_delete'] ?? [])->isNotEmpty() ||
            collect($payload['proposed_document_changes']['files_to_add'] ?? [])->isNotEmpty() ||
            $request->hasFile('files')
        );

        if (!$hasFieldChanges && !$hasDocumentChanges) {
            return response()->json([
                'error' => 'Please provide at least one change: either modify field values or add/delete documents',
            ], 422);
        }

        try {
            // Upload new files if any and get document IDs
            $documentIds = [];
            if ($request->hasFile('files')) {
                $uploadedFiles = $request->file('files');
                if (!is_array($uploadedFiles)) {
                    $uploadedFiles = [$uploadedFiles];
                }

                foreach ($uploadedFiles as $file) {
                    $document = $this->uploadRevisionFile($file, $taxCase, $stageCode);
                    $documentIds[] = $document->id;
                }

                // Add uploaded document IDs to files_to_add
                $payload['proposed_document_changes']['files_to_add'] = array_merge(
                    $payload['proposed_document_changes']['files_to_add'] ?? [],
                    $documentIds
                );
            }

            Log::info('RevisionController: Processing revision request', [
                'tax_case_id' => $taxCase->id,
                'stage_code' => $stageCode,
                'uploaded_document_ids' => $documentIds,
                'proposed_document_changes' => $payload['proposed_document_changes'],
            ]);

            $revision = $this->revisionService->requestRevision(
                $taxCase,
                $user,
                $payload['proposed_values'] ?? [],
                $payload['proposed_document_changes'],
                $payload['reason'],
                $payload['fields'],
                $stageCode
            );

            Log::info('RevisionController: Revision created successfully', [
                'revision_id' => $revision->id,
                'proposed_document_changes' => $revision->proposed_document_changes,
            ]);

            // Load document details for response
            $docChanges = $revision->proposed_document_changes ?? [];
            $documentIds = array_merge(
                $docChanges['files_to_delete'] ?? [],
                $docChanges['files_to_add'] ?? []
            );

            $documents = [];
            if (!empty($documentIds)) {
                $documents = Document::whereIn('id', $documentIds)
                    ->where('tax_case_id', $taxCase->id)
                    ->get(['id', 'original_filename'])
                    ->keyBy('id')
                    ->toArray();
            }

            $revisionData = $revision->load('requestedBy')->toArray();
            $revisionData['documents'] = $documents;

            return response()->json([
                'message' => 'Revision requested successfully',
                'revision' => $revisionData,
            ], 201);
        } catch (ValidationException $e) {
            foreach ($documentIds ?? [] as $documentId) {
                $document = Document::find($documentId);
                if ($document) {
                    Storage::disk(config('filesystems.default'))->delete($document->file_path);
                    $document->forceDelete();
                }
            }
            throw $e;
        } catch (\Exception $e) {
            foreach ($documentIds ?? [] as $documentId) {
                $document = Document::find($documentId);
                if ($document) {
                    Storage::disk(config('filesystems.default'))->delete($document->file_path);
                    $document->forceDelete();
                }
            }
            Log::error('RevisionController: Error requesting revision', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to request revision: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload a file for revision
     */
    private function uploadRevisionFile($file, TaxCase $taxCase, $stageCode = '1')
    {
        $disk = config('filesystems.default');
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = "tax_cases/{$taxCase->id}/revisions";

        $filePath = Storage::disk($disk)->putFileAs($path, $file, $filename);

        $fileHash = hash_file('sha256', $file->getRealPath());

        $document = Document::create([
            'documentable_type' => 'TaxCase',
            'documentable_id' => $taxCase->id,
            'tax_case_id' => $taxCase->id,
            'document_type' => 'revision_document',
            'stage_code' => (string)$stageCode,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'file_mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'hash' => $fileHash,
            'description' => 'Revision document',
            'uploaded_by' => auth()->id(),
            'uploaded_at' => now(),
            'status' => 'DRAFT',
        ]);

        return $document;
    }

    /**
     * User submits revised data for an approved revision
     * User fills in the revised values that were approved
     * NEW FLOW: User submits revised data → Holding decides to grant/reject
     * 
     * PATCH /api/tax-cases/{taxCase}/revisions/{revision}/submit
     */
    public function submitRevisedData(Request $request, TaxCase $taxCase, Revision $revision): JsonResponse
    {
        $this->ensureRevisionBelongsToTaxCase($revision, $taxCase);
        return response()->json([
            'error' => 'Deprecated endpoint: approval is the sole Revision apply point.',
        ], 409);
    }

    /**
     * Holding decides on revision - APPROVED (apply to tax_case) or REJECTED (discard)
     * NEW FLOW: Direct decision with optional rejection reason
     * Files are uploaded AFTER approval (not before)
     * 
     * PATCH /api/tax-cases/{taxCase}/revisions/{revision}/decide
     */
    public function decideRevision(Request $request, TaxCase $taxCase, Revision $revision): JsonResponse
    {
        $this->ensureRevisionBelongsToTaxCase($revision, $taxCase);

        $user = auth()->user();
        
        // Ensure entity is loaded for authorization
        $user->load('entity');
        
        $this->authorize('decide', $revision);

        $validated = $request->validate([
            'decision' => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:decision,reject|string|max:1000',
        ]);

        try {
            $revision = $this->revisionService->decideRevision(
                $revision,
                $taxCase,
                $user,
                $validated['decision'],
                $validated['rejection_reason'] ?? null
            );

            return response()->json([
                'message' => $validated['decision'] === 'approve' ? 'Revision approved and applied' : 'Revision rejected',
                'revision' => $revision->load(['requestedBy']),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get revision detail with before-after comparison
     */
    public function showRevision(TaxCase $taxCase, Revision $revision): JsonResponse
    {
        $this->ensureRevisionBelongsToTaxCase($revision, $taxCase);
        $this->authorize('view', $revision);

        $revision->load([
            'requestedBy:id,name,email',
            'approvedBy:id,name,email',
        ]);

        // Load referenced documents for displaying their names
        $docChanges = $revision->proposed_document_changes ?? [];
        $documentIds = array_merge(
            $docChanges['files_to_delete'] ?? [],
            $docChanges['files_to_add'] ?? []
        );

        $documents = [];
        if (!empty($documentIds)) {
            $documents = Document::whereIn('id', $documentIds)
                ->where('tax_case_id', $taxCase->id)
                ->get(['id', 'original_filename'])
                ->keyBy('id')
                ->toArray();
        }

        // Add documents data to revision response
        $revisionData = $revision->toArray();
        $revisionData['documents'] = $documents;

        return response()->json([
            'data' => $revisionData,
        ]);
    }

    /**
     * List all revisions for a TaxCase
     * 
     * GET /api/tax-cases/{caseId}/revisions
     */
    public function indexRevisions(TaxCase $taxCase): JsonResponse
    {
        // Route middleware enforces this too; retain it at the controller
        // boundary because history visibility follows TaxCase visibility,
        // not the ability to approve a Revision.
        $this->authorize('view', $taxCase);

        $user = auth()->user()->load('entity');

        $revisions = $taxCase->revisions()
            ->with([
                'requestedBy:id,name,email',
                'approvedBy:id,name,email',
            ])
            ->latest()
            ->get();

        $documentIds = $revisions->flatMap(fn ($revision) => array_merge(
            $revision->proposed_document_changes['files_to_delete'] ?? [],
            $revision->proposed_document_changes['files_to_add'] ?? []
        ))->filter()->unique()->values();
        $documents = Document::withTrashed()->whereIn('id', $documentIds)->where('tax_case_id', $taxCase->id)
            ->get(['id', 'original_filename'])->keyBy('id');
        $revisions->each(function ($revision) use ($documents, $user, $taxCase) {
            $ids = collect(array_merge($revision->proposed_document_changes['files_to_delete'] ?? [], $revision->proposed_document_changes['files_to_add'] ?? []));
            $revision->documents = $documents->only($ids->all())->toArray();
            // This is presentation capability only. RevisionPolicy remains the
            // authorization authority for the decision endpoint.
            // All rows belong to the route TaxCase, so hydrate that relation
            // just for the policy evaluation and avoid a TaxCase query per row.
            $revision->setRelation('revisable', $taxCase);
            $revision->can_decide = $revision->revision_status === 'requested'
                && $user->can('decide', $revision);
            $revision->unsetRelation('revisable');
        });

        return response()->json([
            'data' => $revisions,
        ]);
    }

    private function ensureRevisionBelongsToTaxCase(Revision $revision, TaxCase $taxCase): void
    {
        abort_unless(
            in_array($revision->revisable_type, ['TaxCase', TaxCase::class], true)
                && (int) $revision->revisable_id === (int) $taxCase->id,
            404,
            'Revision not found for this tax case.'
        );
    }

    private function ensureDocumentsBelongToTaxCase(array $documentIds, TaxCase $taxCase, int $stageCode): void
    {
        $documentIds = collect($documentIds)->filter()->unique()->values();
        if ($documentIds->isEmpty()) {
            return;
        }

        $ownedCount = Document::query()
            ->whereIn('id', $documentIds)
            ->where('tax_case_id', $taxCase->id)
            ->when($stageCode > 0, fn ($query) => $query->where('stage_code', (string) $stageCode))
            ->count();

        abort_unless($ownedCount === $documentIds->count(), 404, 'Document not found for this tax case.');
    }
}
