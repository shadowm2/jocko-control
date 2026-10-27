<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('jocko.require_https')) {
            return $next($request);
        }

        if (! $request->isSecure()) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'https_required', 'message' => 'HTTPS is required.'],
                ], 403);
            }

            return redirect()->secure($request->getRequestUri(), 308);
        }

        $response = $next($request);
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        return $response;
    }
}
