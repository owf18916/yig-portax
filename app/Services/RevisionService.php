<?php

namespace App\Services;

use App\Models\Revision;
use App\Models\TaxCase;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Events\RevisionRequested;
use App\Events\RevisionApproved;
use App\Events\RevisionRejected;

class RevisionService
{
    /**
     * Request a revision on a model (TaxCase, SKP, SPHP, etc)
     */
    public function requestRevision(
        Model $revisable,
        User $requestedBy,
        array $proposedValues,
        array $proposedDocumentChanges,
        string $reason,
        array $fields = []
    ): Revision {
        return DB::transaction(function () use (
            $revisable,
            $requestedBy,
            $proposedValues,
            $proposedDocumentChanges,
            $reason,
            $fields
        ) {
            // Check if there's already a pending revision
            $pendingRevision = $revisable->revisions()
                ->whereIn('revision_status', ['requested'])
                ->first();

            if ($pendingRevision) {
                throw new \Exception('There is already a revision pending review');
            }

            // Prepare original data (only include fields being revised)
            $originalData = [];
            foreach ($fields as $field) {
                if ($field === 'supporting_docs') {
                    // For docs, store current document IDs
                    if (method_exists($revisable, 'documents')) {
                        $originalData[$field] = $revisable->documents()->pluck('id')->toArray();
                    }
                } else {
                    $originalData[$field] = $revisable->$field ?? null;
                }
            }

            // Filter out null values from proposed values
            $filteredProposedValues = array_filter(
                $proposedValues,
                fn($value) => $value !== null
            );

            // Create revision record
            $revision = Revision::create([
                'revisable_type' => class_basename($revisable),
                'revisable_id' => $revisable->id,
                'revision_status' => 'requested',
                'original_data' => $originalData,
                'proposed_values' => $filteredProposedValues,
                'proposed_document_changes' => $proposedDocumentChanges,
                'requested_by' => $requestedBy->id,
                'requested_at' => now(),
                'reason' => $reason,
            ]);

            // Fire event
            event(new RevisionRequested($revision, $revisable));

            return $revision->load(['requestedBy']);
        });
    }

    /**
     * Decide on revision (approve or reject)
     */
    public function decideRevision(
        Revision $revision,
        Model $revisable,
        User $decidedBy,
        string $decision,
        ?string $rejectionReason = null
    ): Revision {
        return DB::transaction(function () use (
            $revision,
            $revisable,
            $decidedBy,
            $decision,
            $rejectionReason
        ) {
            if (!$revision->isPending()) {
                throw new \Exception('Can only decide revisions in requested status');
            }

            if ($decision === 'approve') {
                return $this->approveRevision($revision, $revisable, $decidedBy);
            } else {
                return $this->rejectRevision($revision, $revisable, $decidedBy, $rejectionReason);
            }
        });
    }

    /**
     * Approve revision and apply changes
     */
    private function approveRevision(
        Revision $revision,
        Model $revisable,
        User $decidedBy
    ): Revision {
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

            // Delete marked files
            if (!empty($docChanges['files_to_delete'])) {
                Document::whereIn('id', $docChanges['files_to_delete'])
                    ->delete();
            }

            // Link new files to the tax case
            if (!empty($docChanges['files_to_add'])) {
                // Update existing documents to link them to this revisable
                Document::whereIn('id', $docChanges['files_to_add'])
                    ->update([
                        'documentable_type' => class_basename($revisable),
                        'documentable_id' => $revisable->id,
                    ]);
            }
        }

        // Update revision status
        $revision->update([
            'revision_status' => 'approved',
            'approved_by' => $decidedBy->id,
            'approved_at' => now(),
        ]);

        // Apply updates to revisable model
        if (!empty($updates)) {
            $revisable->update($updates);
        }

        event(new RevisionApproved($revision));

        return $revision->refresh();
    }

    /**
     * Reject revision
     */
    private function rejectRevision(
        Revision $revision,
        Model $revisable,
        User $decidedBy,
        ?string $rejectionReason
    ): Revision {
        $revision->update([
            'revision_status' => 'rejected',
            'approved_by' => $decidedBy->id,
            'approved_at' => now(),
            'rejection_reason' => $rejectionReason,
        ]);

        event(new RevisionRejected($revision));

        return $revision->refresh();
    }
}
