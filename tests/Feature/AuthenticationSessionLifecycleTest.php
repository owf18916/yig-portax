<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_testing_fixture_cannot_substitute_a_guest_identity(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertFalse(app()->isLocal());
        User::factory()->create(['is_active' => true]);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/tax-cases', [])->assertUnauthorized();
        $this->getJson('/api/documents/1/download')->assertUnauthorized();
        $this->getJson('/api/tax-cases/export')->assertUnauthorized();
    }

    public function test_login_cookie_me_logout_and_old_cookie_lifecycle_without_acting_as(): void
    {
        $user = User::factory()->create(['password' => bcrypt('fixture-password'), 'is_active' => true]);
        $this->getJson('/api/me')->assertUnauthorized();
        $before = session()->getId();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'fixture-password'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertNotSame($before, session()->getId());
        $cookie = session()->getId();
        // Forget the cached guard: identity must be restored from the session cookie.
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $cookie)->getJson('/api/me')
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/logout')->assertOk()->assertJsonPath('success', true);
        $this->assertNotSame($cookie, session()->getId());
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $cookie)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_bad_login_active_check_and_validation_keep_distinct_statuses(): void
    {
        $user = User::factory()->create(['password' => bcrypt('fixture-password'), 'is_active' => false]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'fixture-password'])->assertForbidden();
        $this->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->getJson('/api/me')->assertUnauthorized();
    }
}
