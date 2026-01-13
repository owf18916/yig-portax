<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Revision;
use App\Models\TaxCase;
use App\Events\RevisionRequested;
use App\Events\RevisionApproved;
use App\Events\RevisionRejected;
use App\Events\RevisionSubmitted;
use App\Events\RevisionGranted;
use App\Events\RevisionNotGranted;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class RevisionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Request a revision for SPT Filling (TaxCase)
     * 
     * POST /api/tax-cases/{caseId}/revisions/request
     */
    public function requestRevision(Request $request, TaxCase $taxCase): JsonResponse
    {
        $this->authorize('request', [Revision::class, $taxCase]);

        $validated = $request->validate([
            'fields' => 'required|array|min:1',
            'fields.*' => 'string|in:spt_number,filing_date,received_date,reported_amount,disputed_amount,vat_in_amount,vat_out_amount,description',
            'reason' => 'required|string|min:10|max:1000',
        ]);

        // Check if data is submitted
        if (!$taxCase->submitted_at) {
            return response()->json([
                'error' => 'Cannot request revision for unsubmitted data',
            ], 422);
        }

        // Check if there's already a pending revision
        $pendingRevision = $taxCase->revisions()
            ->whereIn('revision_status', ['PENDING_APPROVAL', 'APPROVED', 'SUBMITTED'])
            ->first();

        if ($pendingRevision) {
            return response()->json([
                'error' => 'There is already a revision in progress',
                'pending_revision_id' => $pendingRevision->id,
            ], 422);
        }

        // Prepare original data (only include fields being revised)
        $originalData = [];
        foreach ($validated['fields'] as $field) {
            $originalData[$field] = $taxCase->$field;
        }

        // Create revision record
        $revision = DB::transaction(function () use ($taxCase, $validated, $originalData) {
            $revision = Revision::create([
                'revisable_type' => 'TaxCase',
                'revisable_id' => $taxCase->id,
                'revision_status' => 'PENDING_APPROVAL',
                'original_data' => $originalData,
                'revised_data' => null,
                'requested_by' => auth()->id(),
                'requested_at' => now(),
                'reason' => $validated['reason'],
            ]);

            // Update tax case revision status
            $taxCase->update(['revision_status' => 'IN_REVISION']);

            return $revision;
        });

        event(new RevisionRequested($revision, $taxCase));

        return response()->json([
            'message' => 'Revision request submitted successfully',
            'revision' => $revision->load(['requestedBy', 'revisable']),
        ], 201);
    }

    /**
     * Approve or reject a revision request (Holding only)
     * 
     * PATCH /api/revisions/{id}/approve
     */
    public function approveRevision(Request $request, Revision $revision): JsonResponse
    {
        $this->authorize('approve', $revision);

        if (!$revision->isPending()) {
            return response()->json([
                'error' => 'Can only approve revisions in PENDING_APPROVAL status',
            ], 422);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|string|max:1000',
        ]);

        $revision = DB::transaction(function () use ($revision, $validated) {
            if ($validated['action'] === 'approve') {
                $revision->update([
                    'revision_status' => 'APPROVED',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'approval_reason' => $validated['reason'] ?? null,
                ]);
                event(new RevisionApproved($revision));
            } else {
                $revision->update([
                    'revision_status' => 'REJECTED',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                    'rejection_reason' => $validated['reason'],
                ]);

                // Revert tax case revision status
                $revision->revisable->update(['revision_status' => 'CURRENT']);
                event(new RevisionRejected($revision));
            }

            return $revision;
        });

        return response()->json([
            'message' => "Revision {$validated['action']}ed successfully",
            'revision' => $revision->load(['approvedBy', 'revisable']),
        ]);
    }

    /**
     * Submit revised data (User/PIC only, after approval)
     * 
     * PATCH /api/revisions/{id}/submit
     */
    public function submitRevision(Request $request, Revision $revision): JsonResponse
    {
        $this->authorize('submit', $revision);

        if (!$revision->isApproved()) {
            return response()->json([
                'error' => 'Can only submit revisions in APPROVED status',
            ], 422);
        }

        $validated = $request->validate([
            'revised_data' => 'required|array',
        ]);

        // Validate that revised_data keys match original_data keys
        $originalKeys = array_keys($revision->original_data);
        $revisedKeys = array_keys($validated['revised_data']);

        if (sort($originalKeys) !== sort($revisedKeys)) {
            return response()->json([
                'error' => 'Revised data fields must match original fields',
            ], 422);
        }

        $revision = DB::transaction(function () use ($revision, $validated) {
            $revision->update([
                'revision_status' => 'SUBMITTED',
                'revised_data' => $validated['revised_data'],
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
            ]);

            event(new RevisionSubmitted($revision));

            return $revision;
        });

        return response()->json([
            'message' => 'Revision submitted successfully',
            'revision' => $revision->load(['submittedBy', 'revisable']),
        ]);
    }

    /**
     * Decide on submitted revision (Holding only)
     * 
     * PATCH /api/revisions/{id}/decide
     */
    public function decideRevision(Request $request, Revision $revision): JsonResponse
    {
        $this->authorize('decide', $revision);

        if (!$revision->isSubmitted()) {
            return response()->json([
                'error' => 'Can only decide on revisions in SUBMITTED status',
            ], 422);
        }

        $validated = $request->validate([
            'decision' => 'required|in:grant,not_grant',
            'reason' => 'required|string|min:10|max:1000',
        ]);

        $revision = DB::transaction(function () use ($revision, $validated) {
            if ($validated['decision'] === 'grant') {
                // Update the original data with revised data
                $taxCase = $revision->revisable;
                $taxCase->update($revision->revised_data);

                $revision->update([
                    'revision_status' => 'GRANTED',
                    'decided_by' => auth()->id(),
                    'decided_at' => now(),
                    'decision_reason' => $validated['reason'],
                ]);

                // Update tax case to mark as revised and link to this revision
                $taxCase->update([
                    'revision_status' => 'REVISED',
                    'last_revision_id' => $revision->id,
                ]);

                event(new RevisionGranted($revision));
            } else {
                $revision->update([
                    'revision_status' => 'NOT_GRANTED',
                    'decided_by' => auth()->id(),
                    'decided_at' => now(),
                    'decision_reason' => $validated['reason'],
                ]);

                // Revert to CURRENT status (can request new revision)
                $revision->revisable->update(['revision_status' => 'CURRENT']);
                event(new RevisionNotGranted($revision));
            }

            return $revision;
        });

        return response()->json([
            'message' => "Revision decision: {$validated['decision']}",
            'revision' => $revision->load(['decidedBy', 'revisable']),
        ]);
    }

    /**
     * Get all revisions for a tax case
     * 
     * GET /api/tax-cases/{caseId}/revisions
     */
    public function listRevisions(TaxCase $taxCase): JsonResponse
    {
        $this->authorize('viewAny', [Revision::class, $taxCase]);

        $revisions = $taxCase->revisions()
            ->with([
                'requestedBy:id,name,email',
                'approvedBy:id,name,email',
                'submittedBy:id,name,email',
                'decidedBy:id,name,email',
            ])
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($revisions);
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
