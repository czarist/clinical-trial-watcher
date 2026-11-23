<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class UserAdministration
{
    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function list(?string $search, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles', 'plan'])
            ->when($search !== null, fn ($query) => $query->where(function ($query) use ($search): void {
                $pattern = '%'.addcslashes((string) $search, '\\%_').'%';
                $query->where('name', 'like', $pattern)->orWhere('email', 'like', $pattern);
            }))
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{plan_id?: int|null, role_ids?: list<int>}  $changes
     */
    public function update(User $user, array $changes): User
    {
        if (array_key_exists('plan_id', $changes)) {
            $user->forceFill(['plan_id' => $changes['plan_id']])->save();
        }

        if (array_key_exists('role_ids', $changes)) {
            $user->roles()->sync($changes['role_ids']);
        }

        return $user->load(['roles', 'plan']);
    }
}
