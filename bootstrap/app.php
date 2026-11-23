<?php

declare(strict_types=1);

use App\Exceptions\ApplicationException;
use App\Http\Middleware\EnsureUserHasPermission;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['permission' => EnsureUserHasPermission::class]);
        $middleware->throttleApi();
        $middleware->redirectGuestsTo('/login');
        $middleware->preventRequestForgery(except: ['stripe/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // Expected business outcomes (invalid credentials, plan limits) are answered, not logged.
        $exceptions->dontReport(ApplicationException::class);

        $exceptions->render(
            fn (ApplicationException $exception) => response()->json(['message' => $exception->getMessage()], $exception->status()),
        );
    })->create();
