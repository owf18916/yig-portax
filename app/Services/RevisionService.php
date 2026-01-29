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
                // For stage 4 (SKP), fetch data from skpRecord
                elseif ((int)$stageCode === 4) {
                    if (!$revisable->relationLoaded('skpRecord')) {
                        $revisable->load('skpRecord');
                    }
                    if ($revisable->skpRecord) {
                        $dataSource = $revisable->skpRecord;
                        Log::info("RevisionService: Using skpRecord as data source");
                    }
                }
                // For stage 5 (Objection Submission), fetch data from objectionSubmission
                elseif ((int)$stageCode === 5) {
                    if (!$revisable->relationLoaded('objectionSubmission')) {
                        $revisable->load('objectionSubmission');
                    }
                    if ($revisable->objectionSubmission) {
                        $dataSource = $revisable->objectionSubmission;
                        Log::info("RevisionService: Using objectionSubmission as data source");
                    }
                }
                // For stage 6 (SPUH), fetch data from spuhRecord
                elseif ((int)$stageCode === 6) {
                    if (!$revisable->relationLoaded('spuhRecord')) {
                        $revisable->load('spuhRecord');
                    }
                    if ($revisable->spuhRecord) {
                        $dataSource = $revisable->spuhRecord;
                        Log::info("RevisionService: Using spuhRecord as data source");
                    }
                }
                // For stage 7 (Objection Decision), fetch data from objectionDecision
                elseif ((int)$stageCode === 7) {
                    if (!$revisable->relationLoaded('objectionDecision')) {
                        $revisable->load('objectionDecision');
                    }
                    if ($revisable->objectionDecision) {
                        $dataSource = $revisable->objectionDecision;
                        Log::info("RevisionService: Using objectionDecision as data source");
                    }
                }
                // For stage 8 (Appeal Submission), fetch data from appealSubmission
                elseif ((int)$stageCode === 8) {
                    if (!$revisable->relationLoaded('appealSubmission')) {
                        $revisable->load('appealSubmission');
                    }
                    if ($revisable->appealSubmission) {
                        $dataSource = $revisable->appealSubmission;
                        Log::info("RevisionService: Using appealSubmission as data source");
                    }
                }
                // For stage 9 (Appeal Explanation Request), fetch data from appealExplanationRequest
                elseif ((int)$stageCode === 9) {
                    if (!$revisable->relationLoaded('appealExplanationRequest')) {
                        $revisable->load('appealExplanationRequest');
                    }
                    if ($revisable->appealExplanationRequest) {
                        $dataSource = $revisable->appealExplanationRequest;
                        Log::info("RevisionService: Using appealExplanationRequest as data source");
                    }
                }
                // For stage 10 (Appeal Decision), fetch data from appealDecision
                elseif ((int)$stageCode === 10) {
                    if (!$revisable->relationLoaded('appealDecision')) {
                        $revisable->load('appealDecision');
                    }
                    if ($revisable->appealDecision) {
                        $dataSource = $revisable->appealDecision;
                        Log::info("RevisionService: Using appealDecision as data source");
                    }
                }
                // For stage 11 (Supreme Court Submission), fetch data from supremeCourtSubmission
                elseif ((int)$stageCode === 11) {
                    if (!$revisable->relationLoaded('supremeCourtSubmission')) {
                        $revisable->load('supremeCourtSubmission');
                    }
                    if ($revisable->supremeCourtSubmission) {
                        $dataSource = $revisable->supremeCourtSubmission;
                        Log::info("RevisionService: Using supremeCourtSubmission as data source");
                    }
                }
                // For stage 12 (Supreme Court Decision), fetch data from supremeCourtDecision
                elseif ((int)$stageCode === 12) {
                    if (!$revisable->relationLoaded('supremeCourtDecision')) {
                        $revisable->load('supremeCourtDecision');
                    }
                    if ($revisable->supremeCourtDecision) {
                        $dataSource = $revisable->supremeCourtDecision;
                        Log::info("RevisionService: Using supremeCourtDecision as data source");
                    }
                }
                // For stage 16 (KIAN Submission), fetch data from kianSubmission
                elseif ((int)$stageCode === 16) {
                    if (!$revisable->relationLoaded('kianSubmission')) {
                        $revisable->load('kianSubmission');
                    }
                    if ($revisable->kianSubmission) {
                        $dataSource = $revisable->kianSubmission;
                        Log::info("RevisionService: Using kianSubmission as data source");
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
            elseif ($stageCode == 4) {
                if (!$revisable->relationLoaded('skpRecord')) {
                    $revisable->load('skpRecord');
                }
                if ($revisable->skpRecord) {
                    $updateTarget = $revisable->skpRecord;
                    Log::info('RevisionService: Using skpRecord as update target');
                }
            }
            elseif ($stageCode == 5) {
                if (!$revisable->relationLoaded('objectionSubmission')) {
                    $revisable->load('objectionSubmission');
                }
                if ($revisable->objectionSubmission) {
                    $updateTarget = $revisable->objectionSubmission;
                    Log::info('RevisionService: Using objectionSubmission as update target');
                }
            }
            elseif ($stageCode == 6) {
                if (!$revisable->relationLoaded('spuhRecord')) {
                    $revisable->load('spuhRecord');
                }
                if ($revisable->spuhRecord) {
                    $updateTarget = $revisable->spuhRecord;
                    Log::info('RevisionService: Using spuhRecord as update target');
                }
            }
            elseif ($stageCode == 7) {
                if (!$revisable->relationLoaded('objectionDecision')) {
                    $revisable->load('objectionDecision');
                }
                if ($revisable->objectionDecision) {
                    $updateTarget = $revisable->objectionDecision;
                    Log::info('RevisionService: Using objectionDecision as update target');
                }
            }
            elseif ($stageCode == 8) {
                if (!$revisable->relationLoaded('appealSubmission')) {
                    $revisable->load('appealSubmission');
                }
                if ($revisable->appealSubmission) {
                    $updateTarget = $revisable->appealSubmission;
                    Log::info('RevisionService: Using appealSubmission as update target');
                }
            }
            elseif ($stageCode == 9) {
                if (!$revisable->relationLoaded('appealExplanationRequest')) {
                    $revisable->load('appealExplanationRequest');
                }
                if ($revisable->appealExplanationRequest) {
                    $updateTarget = $revisable->appealExplanationRequest;
                    Log::info('RevisionService: Using appealExplanationRequest as update target');
                }
            }
            elseif ($stageCode == 10) {
                if (!$revisable->relationLoaded('appealDecision')) {
                    $revisable->load('appealDecision');
                }
                if ($revisable->appealDecision) {
                    $updateTarget = $revisable->appealDecision;
                    Log::info('RevisionService: Using appealDecision as update target');
                }
            }
            elseif ($stageCode == 11) {
                if (!$revisable->relationLoaded('supremeCourtSubmission')) {
                    $revisable->load('supremeCourtSubmission');
                }
                if ($revisable->supremeCourtSubmission) {
                    $updateTarget = $revisable->supremeCourtSubmission;
                    Log::info('RevisionService: Using supremeCourtSubmission as update target');
                }
            }
            elseif ($stageCode == 12) {
                if (!$revisable->relationLoaded('supremeCourtDecision')) {
                    $revisable->load('supremeCourtDecision');
                }
                if ($revisable->supremeCourtDecision) {
                    $updateTarget = $revisable->supremeCourtDecision;
                    Log::info('RevisionService: Using supremeCourtDecision as update target');
                }
            }
            elseif ($stageCode == 16) {
                if (!$revisable->relationLoaded('kianSubmission')) {
                    $revisable->load('kianSubmission');
                }
                if ($revisable->kianSubmission) {
                    $updateTarget = $revisable->kianSubmission;
                    Log::info('RevisionService: Using kianSubmission as update target');
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

            // If decision checkboxes were updated, create a new workflow history entry
            if (isset($updates['create_refund']) || isset($updates['continue_to_next_stage'])) {
                // Get the tax case
                $taxCase = $revisable instanceof \App\Models\TaxCase ? $revisable : $revisable->taxCase;
                
                // Build decision_value JSON with the updated decision data
                $decisionValue = [];
                
                // Include all decision-related fields from the updated model
                if ($updateTarget->hasAttribute('create_refund')) {
                    $decisionValue['create_refund'] = $updateTarget->create_refund ?? false;
                }
                if ($updateTarget->hasAttribute('continue_to_next_stage')) {
                    $decisionValue['continue_to_next_stage'] = $updateTarget->continue_to_next_stage ?? false;
                }
                
                // Add other decision fields if they exist
                $decisionFields = ['decision_type', 'decision_amount', 'decision_number', 'decision_date', 'decision_notes'];
                foreach ($decisionFields as $field) {
                    if ($updateTarget->hasAttribute($field)) {
                        $decisionValue[$field] = $updateTarget->$field;
                    }
                }
                
                // Create workflow history entry for this decision revision
                if (!empty($decisionValue)) {
                    \App\Models\WorkflowHistory::create([
                        'tax_case_id' => $taxCase->id,
                        'stage_from' => $stageCode,
                        'stage_to' => $stageCode,
                        'stage_id' => $stageCode,
                        'decision_value' => json_encode($decisionValue),
                        'user_id' => $decidedBy->id,
                        'notes' => "Decision revised via revision request #{$revision->id}",
                    ]);
                    
                    Log::info('RevisionService: Created workflow history entry for decision revision', [
                        'tax_case_id' => $taxCase->id,
                        'stage_id' => $stageCode,
                        'revision_id' => $revision->id
                    ]);
                }
            }
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
