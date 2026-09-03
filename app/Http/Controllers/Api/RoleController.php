<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->success(
            Role::query()
                ->select('id', 'code', 'name')
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }
}
