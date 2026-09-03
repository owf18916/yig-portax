<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanManageUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasPermission('manage_users')) {
            return new JsonResponse([
                'success' => false,
                'message' => 'You are not authorized to manage users.',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
