<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
final class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->map(fn (Permission $permission): array => [
                'id' => $permission->id,
                'name' => $permission->name,
            ])),
        ];
    }
}
