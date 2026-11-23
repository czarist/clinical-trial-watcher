<?php

declare(strict_types=1);

use App\Exceptions\ApplicationException;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\SetRequestLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

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
        $middleware->api(prepend: [SetRequestLocale::class]);
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

        // The framework's default messages are English; answer API clients in their language.
        $exceptions->render(fn (AuthenticationException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => __('errors.unauthenticated')], 401)
            : null);
        $exceptions->render(fn (AccessDeniedHttpException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => __('errors.forbidden')], 403)
            : null);
        $exceptions->render(fn (NotFoundHttpException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => __('errors.not_found')], 404)
            : null);
        $exceptions->render(fn (TooManyRequestsHttpException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => __('errors.throttled')], 429, $exception->getHeaders())
            : null);
    })->create();
