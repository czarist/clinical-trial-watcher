<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        [$user, $token] = $this->auth->register(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return response()->json([
            ...$token->toArray(),
            'user' => new UserResource($user->load(['roles.permissions', 'plan'])),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated();

        return response()->json($this->auth->login($credentials)->toArray());
    }

    public function refresh(): JsonResponse
    {
        return response()->json($this->auth->refresh()->toArray());
    }

    public function logout(): Response
    {
        $this->auth->logout();

        return response()->noContent();
    }

    public function me(Request $request, BillingService $billing): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return (new UserResource($user->load(['roles.permissions', 'plan'])))
            ->additional(['billing' => $billing->summary($user)]);
    }
}
