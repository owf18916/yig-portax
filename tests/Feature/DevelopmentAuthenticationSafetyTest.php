<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DevelopmentAuthenticationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function localFixture(): void
    {
        // The runner must prove isolation before any process-local environment change.
        $this->assertTrue(app()->environment('testing'));
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
        $this->assertSame('array', config('session.driver'));
        app()->instance('env', 'local');
        config(['app.env' => 'local', 'app.debug' => true]);
        $this->assertTrue(app()->isLocal());
    }

    private function assertGuestEndpoints(int $users): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->getJson('/api/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->postJson('/api/tax-cases', [])->assertUnauthorized();
        $this->getJson('/api/tax-cases/1')->assertUnauthorized();
        $this->getJson('/api/documents/1/download')->assertUnauthorized();
        $this->getJson('/api/notification-logs')->assertUnauthorized();
        $this->postJson('/api/logout')->assertUnauthorized();
        $this->assertGuest('web');
        $this->assertNull(session()->get(Auth::guard('web')->getName()));
        $this->assertDatabaseCount('users', $users);
        $this->assertDatabaseCount('tax_cases', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_local_guests_never_select_or_create_users_including_bad_posts(): void
    {
        $this->localFixture();
        $this->assertGuestEndpoints(0);
        $this->postJson('/api/login', [])->assertUnprocessable();
        // The web SPA GET catch-all yields 405 for this absent POST route.
        $this->postJson('/api/dev-login')->assertStatus(405);
        $this->assertDatabaseCount('users', 0);
        $user = User::factory()->create(['is_active' => true]);
        $this->assertGuestEndpoints(1);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnauthorized();
        $user->update(['is_active' => false]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
        $this->assertGuestEndpoints(1);
    }

    public function test_local_cookie_logout_relogin_and_expiry_never_substitute_first_user(): void
    {
        $this->localFixture();
        $a = User::factory()->create(['is_active' => true]);
        $b = User::factory()->create(['is_active' => true]);
        $this->postJson('/api/login', ['email' => $a->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.user.id', $a->id);
        $oldCookie = session()->getId();
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $oldCookie)->getJson('/api/me')
            ->assertOk()->assertJsonPath('data.id', $a->id);
        $this->postJson('/api/logout')->assertOk();
        $this->assertNotSame($oldCookie, session()->getId());
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $oldCookie);
        $this->assertGuestEndpoints(2);
        $this->postJson('/api/login', ['email' => $b->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.user.id', $b->id);
        $bCookie = session()->getId();
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $bCookie)->getJson('/api/health')->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $b->id);
        $this->postJson('/api/dev-login')->assertStatus(405);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $b->id);
        // Exercise the real array handler's timestamp expiry with the login cookie.
        $this->travel(config('session.lifetime') + 1)->minutes();
        $this->assertSame('', session()->getHandler()->read($bCookie));
        // Laravel's in-process test client reuses Store attributes between requests.
        // Clear that request-local copy without saving/destroying the handler data.
        session()->flush();
        Auth::forgetGuards();
        $this->assertGuestEndpoints(2);
        $this->travelBack();
    }

    public static function environments(): array
    {
        return ['local debug' => ['local', true], 'local quiet' => ['local', false],
            'production debug' => ['production', true], 'production quiet' => ['production', false],
            'testing debug' => ['testing', true]];
    }

    public function test_route_list_and_cache_compile_without_dev_login(): void
    {
        $path = str_replace('\\', '/', storage_path('framework/testing/auth-routes.php'));
        // Laravel recognizes leading '/' cache paths; Windows drive prefixes
        // otherwise get incorrectly appended to base_path by normalizeCachePath.
        $cachePath = preg_match('/^[A-Za-z]:\//', $path) ? substr($path, 2) : $path;
        $repository = Env::getRepository();
        $previous = $repository->get('APP_ROUTES_CACHE');
        $repository->set('APP_ROUTES_CACHE', $cachePath);
        try {
            $this->assertSame(realpath(dirname($path)), realpath(dirname(app()->getCachedRoutesPath())));
            $this->assertSame(0, Artisan::call('route:list', ['--path' => 'api', '--json' => true]));
            $routes = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $uris = array_column($routes, 'uri');
            $this->assertContains('api/login', $uris);
            $this->assertContains('api/me', $uris);
            $this->assertNotContains('api/dev-login', $uris);
            $this->assertSame(0, Artisan::call('route:cache'));
            // route:cache boots a fresh application; restore this test container.
            \Illuminate\Container\Container::setInstance($this->app);
            \Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            require $path;
            $this->assertInstanceOf(\Illuminate\Routing\CompiledRouteCollection::class, Route::getRoutes());
            foreach (Route::getRoutes() as $route) {
                $this->assertNotSame('api/dev-login', $route->uri());
            }
            $this->postJson('/api/dev-login')->assertStatus(405);
            $this->getJson('/api/me')->assertUnauthorized();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if ($previous === null) {
                $repository->clear('APP_ROUTES_CACHE');
            } else {
                $repository->set('APP_ROUTES_CACHE', $previous);
            }
        }
    }

    #[DataProvider('environments')]
    public function test_route_registration_never_exposes_dev_login(string $environment, bool $debug): void
    {
        $this->localFixture();
        app()->instance('env', $environment);
        config(['app.env' => $environment, 'app.debug' => $debug]);
        // Re-register the actual route source under each effective configuration.
        Route::setRoutes(new RouteCollection);
        Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
        $this->assertSame($environment, app()->environment());
        foreach (Route::getRoutes() as $route) {
            $this->assertNotSame('api/dev-login', $route->uri());
        }
        $this->withHeaders(['X-Forwarded-For' => '127.0.0.1', 'Origin' => 'http://localhost'])
            ->postJson('/api/dev-login')->assertNotFound();
        $user = User::factory()->create(['is_active' => true]);
        $this->assertGuestEndpoints(1);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $cookie = session()->getId();
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $cookie)->getJson('/api/health')->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/dev-login')->assertNotFound();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }
}
