<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventIdentityProbeCookieRefresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Run outside EncryptCookies/StartSession/ValidateCsrfToken: a delayed
        // identity probe must not replace a newer login's browser cookies.
        // Session loading, authentication and persistence still run normally.
        foreach ($response->headers->getCookies() as $cookie) {
            if (in_array($cookie->getName(), [config('session.cookie'), 'XSRF-TOKEN'], true)) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
