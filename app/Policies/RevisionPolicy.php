<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Revision;
use App\Models\TaxCase;

class RevisionPolicy
{
    /**
     * Determine whether user can request a revision
     */
    public function request(User $user, Revision $revisionModel, TaxCase $taxCase): bool
    {
        // Only User/PIC (not Holding) can request revision
        // User must be either:
        // 1. The owner of the case (user_id), or
        // 2. Have a role that allows requesting revisions (USER or PIC role)

        if ($user->hasRole('HOLDING')) {
            return false;
        }

        if ($user->hasRole('ADMIN')) {
            return true;
        }

        // Check if user is PIC for the entity or is the case owner
        return $taxCase->entity_id === $user->entity_id || $taxCase->user_id === $user->id;
    }

    /**
     * Determine whether user can approve/reject a revision request
     */
    public function approve(User $user, Revision $revision): bool
    {
        // Only Holding can approve revision requests
        if (!$user->hasRole('HOLDING')) {
            return false;
        }

        // Only can approve if status is PENDING_APPROVAL
        return $revision->isPending();
    }

    /**
     * Determine whether user can submit revised data
     */
    public function submit(User $user, Revision $revision): bool
    {
        // Only the user who requested the revision can submit revised data
        // And only if it was approved
        if ($user->hasRole('HOLDING')) {
            return false;
        }

        return $revision->requested_by === $user->id && $revision->isApproved();
    }

    /**
     * Determine whether user can decide on a submitted revision
     */
    public function decide(User $user, Revision $revision): bool
    {
        // Only Holding can decide on revisions
        if (!$user->hasRole('HOLDING')) {
            return false;
        }

        // Only can decide if status is SUBMITTED
        return $revision->isSubmitted();
    }

    /**
     * Determine whether user can view a specific revision
     */
    public function view(User $user, Revision $revision): bool
    {
        // Can view if:
        // 1. User is the one who requested it
        // 2. User is Holding (can view all)
        // 3. User is Admin
        // 4. User is from the same entity and has permission

        if ($user->hasRole('HOLDING') || $user->hasRole('ADMIN')) {
            return true;
        }

        if ($revision->requested_by === $user->id) {
            return true;
        }

        // Check if user is from the same entity
        $taxCase = $revision->revisable;
        if ($taxCase && $taxCase->entity_id === $user->entity_id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether user can view revisions for a tax case
     */
    public function viewAny(User $user, array $args): bool
    {
        [$revisionModel, $taxCase] = $args;

        // Can view if:
        // 1. User is Holding (can view all)
        // 2. User is Admin
        // 3. User is owner of the case
        // 4. User is from the same entity

        if ($user->hasRole('HOLDING') || $user->hasRole('ADMIN')) {
            return true;
        }

        return $taxCase->entity_id === $user->entity_id || $taxCase->user_id === $user->id;
    }
}
