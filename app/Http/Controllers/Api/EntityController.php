<?php

namespace App\Http\Controllers\Api;

use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EntityController extends ApiController
{
    /**
     * Display a listing of entities for authenticated user
     * - HOLDING users see all entities
     * - AFFILIATE users see only their assigned entity
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return $this->error('Unauthorized', 401);
        }

        // Get accessible entities based on user's entity_type
        if ($user->entity && $user->entity->entity_type !== 'HOLDING') {
            // AFFILIATE users: see only their own entity
            $entities = Entity::select('id', 'name', 'code', 'entity_type', 'tax_id')
                ->where('is_active', true)
                ->where('id', $user->entity_id)
                ->orderBy('name')
                ->get();
        } else {
            // HOLDING users or admin: see all active entities
            $entities = Entity::select('id', 'name', 'code', 'entity_type', 'tax_id')
                ->where('is_active', true)
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
