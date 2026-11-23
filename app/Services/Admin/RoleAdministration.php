<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Role;

final class RoleAdministration
{
    /**
     * @param  list<int>  $permissionIds
     */
    public function syncPermissions(Role $role, array $permissionIds): Role
    {
        $role->permissions()->sync($permissionIds);

        return $role->load('permissions');
    }
}
