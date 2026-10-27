<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncRolePermissionsRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\RoleService;

final class RolePermissionController extends Controller
{
    public function update(
        SyncRolePermissionsRequest $request,
        Role $role,
        RoleService $roles,
    ): RoleResource {
        $role = $roles->syncPermissions(
            $role,
            $request->validated('permissions'),
            $request->user(),
        );

        $role->load('permissions');

        return new RoleResource($role);
    }
}
