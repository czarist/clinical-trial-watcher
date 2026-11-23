<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncRolePermissionsRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Admin\RoleAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RoleController extends Controller
{
    public function __construct(private readonly RoleAdministration $roles) {}

    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::query()->with('permissions')->orderBy('name')->get());
    }

    public function permissions(): JsonResponse
    {
        return response()->json([
            'data' => Permission::query()->orderBy('name')->get(['id', 'name', 'description']),
        ]);
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): RoleResource
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', $request->validated('permission_ids'));

        return new RoleResource($this->roles->syncPermissions($role, $ids));
    }
}
