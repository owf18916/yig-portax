<?php

namespace App\Services;

use App\Models\KianSubmission;
use App\Models\TaxCase;
use Illuminate\Support\Facades\Gate;

final class KianDraftAccess
{
    public function authorize(TaxCase $case): void
    {
        Gate::authorize('create', $case);
    }

    public function isDraft(KianSubmission $kian): bool
    {
        if ($kian->status !== 'draft' || ! in_array($kian->stage_id, [4, 7, 10, 12], true)) return false;
        foreach (KianSubmission::FINAL_FIELDS as $field) if ($kian->$field !== null) return false;
        return true;
    }

    /** Evaluate permission once and reuse the fixed, loaded exposure/submission relations. */
    public function project(TaxCase $case, array $stages): array
    {
        auth()->user()?->loadMissing(['role', 'entity']);
        $allowed = Gate::allows('create', $case);
        $case->loadMissing('kianSubmissions');
        foreach ($stages as $stage => &$state) {
            $kian = $case->kianSubmissions->firstWhere('stage_id', $stage);
            $eligible = $allowed && $state['needsKian'] === true;
            $editable = $eligible && ($kian === null || $this->isDraft($kian));
            $state['draft_actions'] = ['create' => $editable && $kian === null, 'save_draft' => $editable, 'submit' => $editable];
        }
        unset($state);
        return $stages;
    }
}
