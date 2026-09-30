<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Revision;
use App\Models\TaxCase;
use Illuminate\Database\Eloquent\Model;

class RevisionPolicy
{
    /**
     * Determine whether user can request a revision on any model
     * Anyone can request revisions (both HOLDING and non-HOLDING entities)
     */
    public function request(User $user, Revision $revisionModel, Model $revisable): bool
    {
        return $revisable instanceof TaxCase && $user->can('view', $revisable);
    }

    /**
     * Determine whether user can decide (approve/reject) a revision
     */
    public function decide(User $user, Revision $revision): bool
    {
        // Only HOLDING entity can approve/reject
        $taxCase = $this->taxCase($revision);
        return $taxCase !== null
            && $user->can('view', $taxCase)
            && $user->entity?->entity_type === 'HOLDING';
    }

    /**
     * Determine whether user can view a revision
     */
    public function view(User $user, Revision $revision): bool
    {
        $taxCase = $this->taxCase($revision);
        return $taxCase !== null && $user->can('view', $taxCase);
    }

    private function taxCase(Revision $revision): ?TaxCase
    {
        if (!in_array($revision->revisable_type, ['TaxCase', TaxCase::class], true)) {
            return null;
        }

        if ($revision->relationLoaded('revisable')) {
            return $revision->revisable instanceof TaxCase ? $revision->revisable : null;
        }

        return TaxCase::find($revision->revisable_id);
    }
}
