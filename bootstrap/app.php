<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'manage-users' => \App\Http\Middleware\EnsureCanManageUsers::class,
        ]);

        // API routes use the existing web session guard.
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
        // Protected guests keep the established JSON 401 before token checking.
        // Anonymous login still traverses the verifier; bindings/policies follow it.
        $middleware->appendToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        );
        // Only /api/me attaches this response filter. It must unwind after the
        // framework has emitted and encrypted its session and XSRF cookies.
        $middleware->prependToPriorityList(
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \App\Http\Middleware\PreventIdentityProbeCookieRefresh::class,
        );

        // Web routes use CSRF protection (this is default in Laravel)
        // No explicit setup needed here as it's automatic for web middleware group
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
