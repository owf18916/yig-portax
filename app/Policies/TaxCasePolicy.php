<?php

namespace App\Policies;

use App\Models\TaxCase;
use App\Models\User;

class TaxCasePolicy
{
    /**
     * HOLDING users may access every case. Other users are limited to
     * cases owned by their assigned entity. Roles do not bypass this rule.
     */
    public function view(User $user, TaxCase $taxCase): bool
    {
        $entity = $user->relationLoaded('entity')
            ? $user->entity
            : $user->entity()->first();

        return $entity !== null
            && ($entity->entity_type === 'HOLDING'
                || (int) $taxCase->entity_id === (int) $user->entity_id);
    }

    public function create(User $user, TaxCase $taxCase): bool
    {
        return $this->view($user, $taxCase);
    }

    public function update(User $user, TaxCase $taxCase): bool
    {
        return $this->view($user, $taxCase);
    }
}
