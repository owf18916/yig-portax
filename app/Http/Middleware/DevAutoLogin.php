<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class DevAutoLogin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Only in development mode and if not already authenticated
        if (app()->isLocal() && !Auth::check()) {
            // Find or create a test user
            $user = User::first();
            
            if ($user) {
                Auth::login($user);
            }
        }

        return $next($request);
    }
}
