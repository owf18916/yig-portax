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
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            // Note: VerifyCsrfToken is NOT applied to /api routes by default in Laravel 12
            // Only web routes use it
        ]);
        
        // Web routes use CSRF protection (this is default in Laravel)
        // No explicit setup needed here as it's automatic for web middleware group
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
