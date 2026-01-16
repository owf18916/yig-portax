<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\Revision;
use Illuminate\Http\Request;
use App\Events\RevisionGranted;
use App\Events\RevisionApproved;
use App\Events\RevisionRejected;
use App\Events\RevisionRequested;
use App\Events\RevisionSubmitted;
use Illuminate\Http\JsonResponse;
use App\Events\RevisionNotGranted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class RevisionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Request a revision for SPT Filling (TaxCase)
     * NEW FLOW: User proposes values + document changes in one request
     * 
     * POST /api/tax-cases/{caseId}/revisions/request
     */
    public function requestRevision(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Create a dummy Revision instance for policy check
        $dummyRevision = new Revision();
        $this->authorize('request', [$dummyRevision, $taxCase]);

        $validated = $request->validate([
            'fields' => 'required|array|min:1',
            'fields.*' => 'string|in:period_id,currency_id,disputed_amount,supporting_docs',
            'reason' => 'required|string|min:10|max:1000',
            'proposed_values' => 'required|array',
            'proposed_values.period_id' => 'nullable|integer',
            'proposed_values.currency_id' => 'nullable|integer',
            'proposed_values.disputed_amount' => 'nullable|numeric|min:0',
            'proposed_document_changes' => 'required|array',
            'proposed_document_changes.files_to_delete' => 'array',
            'proposed_document_changes.files_to_delete.*' => 'integer',
            'proposed_document_changes.files_to_add' => 'array',
            'proposed_document_changes.files_to_add.*' => 'integer',
        ]);

        // Check if data is submitted
        if (!$taxCase->submitted_at) {
            return response()->json([
                'error' => 'Cannot request revision for unsubmitted data',
            ], 422);
        }

        // Check if there's already a pending revision
        $pendingRevision = $taxCase->revisions()
            ->whereIn('revision_status', ['requested'])
            ->first();

        if ($pendingRevision) {
            return response()->json([
                'error' => 'There is already a revision pending review',
                'pending_revision_id' => $pendingRevision->id,
            ], 422);
        }

        // Prepare original data (only include fields being revised)
        $originalData = [];
        foreach ($validated['fields'] as $field) {
            if ($field === 'supporting_docs') {
                // For docs, store current document IDs
                $originalData[$field] = $taxCase->documents()->pluck('id')->toArray();
            } else {
                $originalData[$field] = $taxCase->$field ?? null;
            }
        }

        // Create revision record with proposed values
        $revision = DB::transaction(function () use ($taxCase, $validated, $originalData) {
            // Filter out null values from proposed_values
            $proposedValues = array_filter(
                $validated['proposed_values'],
                fn($value) => $value !== null
            );

            $revision = Revision::create([
                'revisable_type' => 'TaxCase',
                'revisable_id' => $taxCase->id,
                'revision_status' => 'requested',
                'original_data' => $originalData,
                'proposed_values' => $proposedValues,
                'proposed_document_changes' => $validated['proposed_document_changes'],
                'requested_by' => auth()->id(),
                'requested_at' => now(),
                'reason' => $validated['reason'],
            ]);

            return $revision;
        });

        event(new RevisionRequested($revision, $taxCase));

        return response()->json([
            'message' => 'Revision request submitted successfully',
            'revision' => $revision->load(['requestedBy']),
        ], 201);
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
        $this->authorize('decide', $revision);

        if (!$revision->isPending()) {
            return response()->json([
                'error' => 'Can only decide revisions in requested status',
            ], 422);
        }

        $validated = $request->validate([
            'decision' => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:decision,reject|string|max:1000',
            'new_files' => 'array|nullable', // Files uploaded after approval
        ]);

        $revision = DB::transaction(function () use ($revision, $validated, $taxCase) {

            if ($validated['decision'] === 'approve') {
                // APPROVED: Apply proposed values to tax_case
                $updates = [];
                
                // Apply proposed values (non-document fields)
                foreach ($revision->proposed_values ?? [] as $field => $value) {
                    if ($field !== 'supporting_docs' && $value !== null) {
                        $updates[$field] = $value;
                    }
                }

                // Apply document changes
                if (!empty($revision->proposed_document_changes)) {
                    $docChanges = $revision->proposed_document_changes;
                    
                    // 1. Delete marked files FIRST
                    if (!empty($docChanges['files_to_delete'])) {
                        \App\Models\Document::whereIn('id', $docChanges['files_to_delete'])
                            ->delete();
                    }
                    
                    // 2. New files will be uploaded separately by frontend
                    // (they're uploaded AFTER this approval response)
                }

                $revision->update([
                    'revision_status' => 'approved',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);

                // Apply updates to tax case
                if (!empty($updates)) {
                    $taxCase->update($updates);
                }

                event(new RevisionApproved($revision));
            } else {
                // REJECTED: Don't apply changes, just mark as rejected
                // Note: Files were never uploaded, so nothing to clean up
                $revision->update([
                    'revision_status' => 'rejected',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'rejection_reason' => $validated['rejection_reason'] ?? null,
                ]);

                event(new RevisionRejected($revision));
            }

            return $revision;
        });

        return response()->json([
            'message' => "Revision {$validated['decision']}ed successfully",
            'revision' => $revision->load(['approvedBy']),
        ]);
    }

    /**
     * Get all revisions for a tax case
     * 
     * GET /api/tax-cases/{caseId}/revisions
     */
    public function listRevisions(TaxCase $taxCase): JsonResponse
    {
        // Ensure user has role relationship loaded
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($user && !$user->relationLoaded('role')) {
            $user->load('role');
        }

        // Simple check: user must be authenticated
        // Allow all authenticated users to view revisions
        // This is safe because revisions are tied to tax cases which already have access control
        
        // Log::info('listRevisions accessed', [
        //     'user_id' => $user->id,
        //     'user_role' => $user->role?->name,
        //     'tax_case_id' => $taxCase->id,
        // ]);

        $revisions = $taxCase->revisions()
            ->with([
                'requestedBy:id,name,email',
                'approvedBy:id,name,email',
                'submittedBy:id,name,email',
                'decidedBy:id,name,email',
            ])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $revisions,
            'success' => true
        ]);
    }

    /**
     * Get single revision detail with before-after comparison
     * 
     * GET /api/revisions/{id}
     */
    public function showRevision(Revision $revision): JsonResponse
    {
        $this->authorize('view', $revision);

        $revision->load([
            'revisable',
            'requestedBy:id,name,email',
            'approvedBy:id,name,email',
            'submittedBy:id,name,email',
            'decidedBy:id,name,email',
        ]);

        return response()->json([
            'revision' => $revision,
            'comparison' => [
                'original' => $revision->original_data,
                'revised' => $revision->revised_data,
                'changes' => $this->getChanges($revision->original_data, $revision->revised_data),
            ],
        ]);
    }

    /**
     * Calculate differences between original and revised data
     */
    private function getChanges(?array $original, ?array $revised): array
    {
        if (!$revised) {
            return [];
        }

        $changes = [];
        foreach ($original as $field => $originalValue) {
            $revisedValue = $revised[$field] ?? null;
            if ($originalValue !== $revisedValue) {
                $changes[$field] = [
                    'original' => $originalValue,
                    'revised' => $revisedValue,
                ];
            }
        }

        return $changes;
    }
}
