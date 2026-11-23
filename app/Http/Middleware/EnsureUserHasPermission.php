<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "permission:<name>": the authenticated user needs the permission
 * through one of their roles. Use it after auth:api.
 */
final class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, __('errors.unauthenticated'));
        }

        if (! $user->hasPermission($permission)) {
            abort(403, __('errors.forbidden'));
        }

        return $next($request);
    }
}
