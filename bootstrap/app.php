<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']]
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->statefulApi();
        $middleware->validateCsrfTokens(except: [
            'api/auth/login',
            'api/auth/logout',
            'api/broadcasting/auth',
            'api/webhooks/*',
        ]);
        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenantScope::class,
            'meta.webhook.signature' => \App\Http\Middleware\VerifyMetaWebhookSignature::class,
        ]);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => 'file_too_large',
                    'message' => 'The uploaded file is too large. Server post_max_size limit exceeded.'
                ], 413);
            }
        });
    })->create();
