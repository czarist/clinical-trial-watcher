<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLocaleRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AccountService;
use Illuminate\Http\Response;

final class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        $user = $this->accounts->updateProfile(
            $user,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
        );

        return new UserResource($user->load(['roles.permissions', 'plan']));
    }

    public function updateLocale(UpdateLocaleRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $user = $this->accounts->updateLocale($user, $request->locale());

        return new UserResource($user->load(['roles.permissions', 'plan']));
    }

    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->accounts->updatePassword($user, $request->string('password')->toString());

        return response()->noContent();
    }
}
