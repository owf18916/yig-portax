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

        // Parse payload from FormData
        $payloadJson = $request->input('payload');
        $payload = json_decode($payloadJson, true);

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

        // Check if data is submitted via workflow_history (source of truth)
        $isStageSubmitted = $taxCase->workflowHistories()
            ->where('stage_id', $stageCode ?? 1)
            ->whereIn('status', ['submitted', 'approved'])
            ->exists();
            
        if (!$isStageSubmitted) {
            return response()->json([
                'error' => 'Cannot request revision for unsubmitted data',
            ], 422);
        }

        // Custom validation: ensure at least one change is proposed
        $hasFieldChanges = collect($payload['proposed_values'] ?? [])
            ->filter(fn($value) => $value !== null)
            ->isNotEmpty();

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
            // Extract stage_code from payload with default
            $stageCode = $payload['stage_code'] ?? '1';

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
        } catch (\Exception $e) {
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
     * Holding decides on revision - APPROVED (apply to tax_case) or REJECTED (discard)
     * NEW FLOW: Direct decision with optional rejection reason
     * Files are uploaded AFTER approval (not before)
     * 
     * PATCH /api/tax-cases/{taxCase}/revisions/{revision}/decide
     */
    public function decideRevision(Request $request, TaxCase $taxCase, Revision $revision): JsonResponse
    {
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
        $revisions = $taxCase->revisions()
            ->with([
                'requestedBy:id,name,email',
                'approvedBy:id,name,email',
            ])
            ->latest()
            ->get();

        // Add documents data to each revision
        $revisions->each(function ($revision) {
            $docChanges = $revision->proposed_document_changes ?? [];
            $documentIds = array_merge(
                $docChanges['files_to_delete'] ?? [],
                $docChanges['files_to_add'] ?? []
            );

            $documents = [];
            if (!empty($documentIds)) {
                $documents = Document::whereIn('id', $documentIds)
                    ->get(['id', 'original_filename'])
                    ->keyBy('id')
                    ->toArray();
            }

            $revision->documents = $documents;
        });

        return response()->json([
            'data' => $revisions,
        ]);
    }
}
