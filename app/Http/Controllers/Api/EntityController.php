<?php

namespace App\Http\Controllers\Api;

use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EntityController extends ApiController
{
    /**
     * Display a listing of entities for authenticated user
     * - Admins see all entities
     * - Other users see only their assigned entity
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return $this->error('Unauthorized', 401);
        }

        // Admins can see all entities
        if ($user->role_id === 1) { // 1 = admin
            $entities = Entity::select('id', 'name', 'code', 'entity_type', 'tax_id')
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        } else {
            // Non-admins see only their assigned entity
            $entities = Entity::select('id', 'name', 'code', 'entity_type', 'tax_id')
                ->where('is_active', true)
                ->where('id', $user->entity_id)
                ->orderBy('name')
                ->get();
        }

        return $this->success($entities);
    }

    /**
     * Display a specific entity
     */
    public function show(Entity $entity): JsonResponse
    {
        return $this->success($entity);
    }
}
