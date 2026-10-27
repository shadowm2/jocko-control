<?php

use App\Http\Middleware\AuthorizeDevice;
use App\Http\Middleware\EnforceHttps;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\LimitRequestSize;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $proxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));

        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
        }

        $middleware->prepend(EnforceHttps::class);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'device' => AuthorizeDevice::class,
            'request.limit' => LimitRequestSize::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The device API always answers with the same small, safe error envelope.
        $error = fn (string $code, string $message, int $status, array $extra = []) => response()->json(
            ['error' => ['code' => $code, 'message' => $message] + $extra],
            $status,
        );

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? $error('unauthenticated', 'Authentication is required.', 401)
            : null);

        $exceptions->render(fn (ValidationException $e, Request $request) => $request->is('api/*')
            ? $error('validation_failed', 'The request is invalid.', 422, ['details' => $e->errors()])
            : null);

        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => $request->is('api/*')
            ? $error('not_found', 'The requested resource was not found.', 404)
            : null);

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($error) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();
            $headers = $e->getHeaders();

            $code = match ($status) {
                404 => 'not_found',
                405 => 'method_not_allowed',
                413 => 'request_too_large',
                429 => 'too_many_requests',
                default => 'http_error',
            };

            $extra = isset($headers['Retry-After']) ? ['retry_after' => (int) $headers['Retry-After']] : [];

            return $error($code, $status === 429 ? 'Too many requests.' : 'The request could not be processed.', $status, $extra)
                ->withHeaders($headers);
        });

        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*')
            ? $error('server_error', 'The server could not complete the request.', 500)
            : null);
    })->create();
