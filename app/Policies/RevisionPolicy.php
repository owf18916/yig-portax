<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Revision;
use App\Models\TaxCase;
use Illuminate\Support\Facades\Log;

class RevisionPolicy
{
    /**
     * Bypass authorization for admins and system users
     */
    public function before(User $user): ?bool
    {
        // Get user role name (handle both direct name and through relationship)
        $roleName = null;
        if ($user->role) {
            $roleName = $user->role->name;
        } elseif ($user->relationLoaded('role') && $user->role) {
            $roleName = $user->role->name;
        }

        // Log for debugging
        Log::debug('RevisionPolicy before() called', [
            'user_id' => $user->id,
            'role_loaded' => (bool)$user->role,
            'role_name' => $roleName,
        ]);

        // Allow all actions for Administrator role
        if ($roleName && in_array($roleName, ['ADMIN', 'Administrator', 'admin'])) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether user can request a revision
     */
    public function request(User $user, Revision $revisionModel, TaxCase $taxCase): bool
    {
        $roleName = $user->role ? $user->role->name : null;

        // Only User/PIC (not Holding) can request revision
        if (in_array($roleName, ['HOLDING', 'Holding'])) {
            return false;
        }

        // Admin can always request
        if (in_array($roleName, ['ADMIN', 'Administrator', 'admin'])) {
            return true;
        }

        // Check if user is PIC for the entity or is the case owner
        return ($taxCase->entity_id && $user->entity_id && $taxCase->entity_id === $user->entity_id) || $taxCase->user_id === $user->id;
    }

    /**
     * Determine whether user can approve/reject a revision request
     */
    public function approve(User $user, Revision $revision): bool
    {
        // Check if user's entity is a Holding
        if (!$user->entity_id) {
            return false;
        }
        
        $userEntity = $user->load('entity')->entity;
        if (!$userEntity || $userEntity->entity_type !== 'HOLDING') {
            return false;
        }

        // Only can approve if status is pending (requested)
        return $revision->isPending();
    }

    /**
     * Determine whether user can submit revised data
     */
    public function submit(User $user, Revision $revision): bool
    {
        $roleName = $user->role ? $user->role->name : null;

        // Only the user who requested the revision can submit revised data
        // And only if it was approved
        if (in_array($roleName, ['HOLDING', 'Holding'])) {
            return false;
        }

        return $revision->requested_by === $user->id && $revision->isApproved();
    }

    /**
     * Determine whether user can decide on a submitted revision
     */
    public function decide(User $user, Revision $revision): bool
    {
        // Check if user's entity is a Holding
        if (!$user->entity_id) {
            return false;
        }
        
        $userEntity = $user->load('entity')->entity;
        if (!$userEntity || $userEntity->entity_type !== 'HOLDING') {
            return false;
        }

        // Only can decide if status is pending (requested)
        return $revision->isPending();
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
    public function viewAny(User $user, TaxCase $taxCase): bool
    {
        // Force load role if not already loaded
        if (!$user->relationLoaded('role') && !$user->role) {
            $user->load('role');
        }

        // Get user role name
        $roleName = $user->role ? $user->role->name : null;

        // Log for debugging
        Log::debug('RevisionPolicy viewAny() called', [
            'user_id' => $user->id,
            'role_name' => $roleName,
            'tax_case_id' => $taxCase->id,
            'user_entity_id' => $user->entity_id,
            'case_entity_id' => $taxCase->entity_id,
            'case_user_id' => $taxCase->user_id,
        ]);

        // FIRST: Check if user has admin/holding role - highest priority
        if ($roleName) {
            if (strtolower($roleName) === strtolower('Administrator')) {
                Log::info('RevisionPolicy allowing Administrator', ['user_id' => $user->id]);
                return true;
            }
            if (in_array($roleName, ['HOLDING', 'Holding', 'ADMIN', 'admin'])) {
                Log::info('RevisionPolicy allowing ' . $roleName, ['user_id' => $user->id]);
                return true;
            }
        }

        // 2. Allow case owner
        if ($taxCase->user_id && $taxCase->user_id === $user->id) {
            return true;
        }

        // 3. Allow if same entity
        if ($taxCase->entity_id && $user->entity_id && $taxCase->entity_id === $user->entity_id) {
            return true;
        }

        // 4. Allow other authenticated users with permission roles
        if ($roleName && in_array($roleName, ['USER', 'User', 'PIC', 'Pic', 'USER_PIC'])) {
            return true;
        }

        // 5. Last resort: Allow any authenticated user to view (assumes they have some role)
        if ($user->role) {
            return true;
        }

        // If we get here, deny access
        Log::warning('RevisionPolicy viewAny() denied', [
            'user_id' => $user->id,
            'role_name' => $roleName,
            'tax_case_id' => $taxCase->id,
        ]);

        return false;
    }
}
