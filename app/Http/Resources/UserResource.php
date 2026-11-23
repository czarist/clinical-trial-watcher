<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])),
            'permissions' => $this->whenLoaded('roles', fn () => $this->permissionNames()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
