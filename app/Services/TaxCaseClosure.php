<?php

namespace App\Services;

use App\Models\TaxCase;

class TaxCaseClosure
{
    public function classification(TaxCase $case, bool $stageTwelveSubmitted = false): string
    {
        if ($case->explicitly_closed === true || strtoupper((string) $case->status?->code) === 'CLOSED') {
            return 'closed';
        }
        if ($case->completed_date !== null
            || ($case->explicitly_closed === null && ($case->is_completed || $stageTwelveSubmitted))) {
            return 'legacy_unresolved';
        }

        return 'open';
    }

    /** Called only inside the submission transaction, while the parent row is locked. */
    public function confirmStageTwelveSubmission(TaxCase $case): void
    {
        abort_unless($this->classification($case) === 'open' && ! $case->is_completed,
            409, 'Closed or unresolved legacy tax cases cannot be submitted.');
        $case->forceFill(['explicitly_closed' => false])->save();
    }

    public function canUpdateStageTwelve(TaxCase $case, array $state): bool
    {
        return $case->explicitly_closed === false
            && in_array(12, $state['completed_stages'], true)
            && $this->classification($case, true) === 'open';
    }
}
