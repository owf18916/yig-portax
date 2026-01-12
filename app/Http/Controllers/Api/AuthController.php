<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends ApiController
{
    /**
     * Login user and return auth token
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return $this->error('Invalid email or password', 401);
        }

        if (!$user->is_active) {
            return $this->error('User account is not active', 403);
        }

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Login user using session
        Auth::login($user);

        // Reload user dengan relationships
        $user = User::with('role', 'entity')->find($user->id);

        return $this->success([
            'user' => $user,
            'message' => 'Login successful'
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(null, 'Logout successful');
    }

    /**
     * Get current user
     */
    public function me(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return $this->error('Not authenticated', 401);
        }

        // Return user with eager loaded relationships
        $user = User::with('role', 'entity')->find($user->id);
        return $this->success($user);
    }
}
