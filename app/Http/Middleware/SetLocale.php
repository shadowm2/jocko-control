<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uses the language an administrator picked (kept in a cookie, so it survives
 * signing out) when it is one of `config('jocko.locales')`; otherwise the
 * application's default. The device API is not affected.
 */
class SetLocale
{
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie(self::COOKIE);

        if (is_string($locale) && array_key_exists($locale, config('jocko.locales'))) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
