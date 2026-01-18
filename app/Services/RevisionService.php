<?php

namespace App\Services;

use App\Models\Revision;
use App\Models\TaxCase;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        array $fields = [],
        ?string $stageCode = null
    ): Revision {
        return DB::transaction(function () use (
            $revisable,
            $requestedBy,
            $proposedValues,
            $proposedDocumentChanges,
            $reason,
            $fields,
            $stageCode
        ) {
            // Check if there's already a pending revision
            $pendingRevision = $revisable->revisions()
                ->whereIn('revision_status', ['requested'])
                ->first();

            if ($pendingRevision) {
                throw new \Exception('There is already a revision pending review');
            }

            // Determine the actual data source based on stage
            $dataSource = $revisable;
            
            Log::info("RevisionService: stageCode = {$stageCode}");
            Log::info("RevisionService: revisable type = " . class_basename($revisable));
            
            if ($stageCode && $revisable instanceof \App\Models\TaxCase) {
                Log::info("RevisionService: Is TaxCase, loading relationships");
                
                // For stage 2 (SP2), fetch data from sp2Record
                if ((int)$stageCode === 2) {
                    if (!$revisable->relationLoaded('sp2Record')) {
                        $revisable->load('sp2Record');
                    }
                    if ($revisable->sp2Record) {
                        $dataSource = $revisable->sp2Record;
                        Log::info("RevisionService: Using sp2Record as data source");
                    }
                }
                // For stage 3 (SPHP), fetch data from sphpRecord
                elseif ((int)$stageCode === 3) {
                    if (!$revisable->relationLoaded('sphpRecord')) {
                        $revisable->load('sphpRecord');
                    }
                    if ($revisable->sphpRecord) {
                        $dataSource = $revisable->sphpRecord;
                        Log::info("RevisionService: Using sphpRecord as data source");
                    }
                }
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
                    // Get value from the appropriate data source
                    $value = $dataSource->$field ?? null;
                    $originalData[$field] = $value;
                    Log::info("RevisionService: Field {$field} = " . json_encode($value));
                }
            }

            // Filter out null values from proposed values
            $filteredProposedValues = array_filter(
                $proposedValues,
                fn($value) => $value !== null
            );

            // Create revision record dengan stage_code
            $revision = Revision::create([
                'revisable_type' => class_basename($revisable),
                'revisable_id' => $revisable->id,
                'stage_code' => (int)$stageCode,
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

        // Determine which model to update based on stage_code
        $updateTarget = $revisable;
        $stageCode = $revision->stage_code;
        
        Log::info('RevisionService: Approving revision', [
            'revision_id' => $revision->id,
            'stage_code' => $stageCode,
            'revisable_type' => class_basename($revisable)
        ]);
        
        if ($stageCode && $revisable instanceof \App\Models\TaxCase) {
            // Load appropriate stage-specific relationship
            if ($stageCode == 2) {
                if (!$revisable->relationLoaded('sp2Record')) {
                    $revisable->load('sp2Record');
                }
                if ($revisable->sp2Record) {
                    $updateTarget = $revisable->sp2Record;
                    Log::info('RevisionService: Using sp2Record as update target');
                }
            }
            // Add more stage-specific relationships as they are created
            elseif ($stageCode == 3) {
                if (!$revisable->relationLoaded('sphpRecord')) {
                    $revisable->load('sphpRecord');
                }
                if ($revisable->sphpRecord) {
                    $updateTarget = $revisable->sphpRecord;
                    Log::info('RevisionService: Using sphpRecord as update target');
                }
            }
        }

        // Apply updates to the appropriate model
        if (!empty($updates)) {
            $updateTarget->update($updates);
            Log::info('RevisionService: Updates applied', [
                'updates' => $updates,
                'target_type' => class_basename($updateTarget),
                'target_id' => $updateTarget->id
            ]);
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
