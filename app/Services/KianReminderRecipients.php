<?php

namespace App\Services;

use App\Models\TaxCase;
use App\Models\User;

class KianReminderRecipients
{
    public function resolve(TaxCase $case): array
    {
        $case->loadMissing('entity.parentEntity');
        $parent = $case->entity?->entity_type === 'AFFILIATE' ? $case->entity->parentEntity : null;
        $holdingId = $parent?->entity_type === 'HOLDING' && $parent->is_active ? $parent->id : null;
        $users = User::where('is_active', true)->whereHas('role', fn ($q) => $q->where('is_active', true))
            ->where(function ($q) use ($case, $holdingId) {
                $q->where(function ($q) use ($case) {
                    $q->where('entity_id', $case->entity_id)->where(fn ($q) => $q->whereKey($case->user_id)
                        ->orWhereHas('role', fn ($q) => $q->where('name', 'Manager')));
                });
                if ($holdingId) {
                    $q->orWhere(fn ($q) => $q->where('entity_id', $holdingId)
                        ->whereHas('role', fn ($q) => $q->whereIn('name', ['Coordinator', 'General Manager', 'Vice President'])));
                }
            })->with('role:id,name,is_active')->orderBy('id')->get();
        $to = [];
        $cc = [];
        foreach ($users as $user) {
            $email = strtolower(trim($user->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $isTo = (int) $user->entity_id === (int) $case->entity_id;
            $entry = ['user_id' => $user->id, 'email' => $email, 'entity_id' => $user->entity_id, 'role_id' => $user->role_id,
                'rule' => $isTo ? ((int) $user->id === (int) $case->user_id ? 'active_case_entity_owner' : 'active_case_entity_manager') : 'active_parent_holding_'.$user->role->name];
            if ($isTo) {
                $to[$email][] = $entry;
            } else {
                $cc[$email][] = $entry;
            }
        }
        $cc = array_diff_key($cc, $to);

        return ['to' => array_keys($to), 'cc' => array_keys($cc), 'identities' => ['to' => $to, 'cc' => $cc], 'rule_version' => 'B18.1'];
    }
}
