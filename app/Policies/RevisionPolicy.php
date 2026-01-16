<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Revision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class RevisionPolicy
{
    /**
     * Bypass authorization for admins and system users
     */
    public function before(User $user): ?bool
    {
        $roleName = $user->role?->name;

        Log::debug('RevisionPolicy before() called', [
            'user_id' => $user->id,
            'role_loaded' => (bool)$user->role,
            'role_name' => $roleName,
        ]);

        // Allow all actions for Administrator role
        if (in_array($roleName, ['ADMIN', 'Administrator', 'admin'])) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether user can request a revision on any model
     * Anyone can request revisions (both HOLDING and non-HOLDING entities)
     */
    public function request(User $user, Revision $revisionModel, Model $revisable): bool
    {
        // All authenticated users can request revisions
        return true;
    }

    /**
     * Determine whether user can decide (approve/reject) a revision
     */
    public function decide(User $user, Revision $revision): bool
    {
        // Only HOLDING entity can approve/reject
        $userEntity = $user->entity;
        return $userEntity && $userEntity->entity_type === 'HOLDING';
    }

    /**
     * Determine whether user can view a revision
     */
    public function view(User $user, Revision $revision): bool
    {
        return true;
    }
}
