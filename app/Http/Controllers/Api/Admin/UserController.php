<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\UserAdministration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UserController extends Controller
{
    public function __construct(private readonly UserAdministration $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return UserResource::collection(
            $this->users->list($validated['search'] ?? null, (int) ($validated['per_page'] ?? 20)),
        );
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        /** @var array{plan_id?: int|null, role_ids?: list<int>} $changes */
        $changes = $request->validated();

        return new UserResource($this->users->update($user, $changes));
    }
}
