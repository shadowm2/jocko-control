<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitRequestSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('jocko.sync.max_request_bytes');
        $declared = (int) $request->header('Content-Length', 0);

        if ($declared > $limit || strlen($request->getContent()) > $limit) {
            return response()->json([
                'error' => ['code' => 'request_too_large', 'message' => 'The request body is too large.'],
            ], 413);
        }

        return $next($request);
    }
}
