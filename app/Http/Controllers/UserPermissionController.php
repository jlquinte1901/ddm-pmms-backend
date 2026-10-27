<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncUserPermissionsRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\UserService;

final class UserPermissionController extends Controller
{
    public function update(
        SyncUserPermissionsRequest $request,
        User $user,
        UserService $users,
        AuthorizationService $authorization,
    ): UserResource {
        $user = $users->syncPermissions(
            $user,
            $request->validated('permissions'),
            $request->user(),
        );

        $user->load('role');
        $user->setRelation('effectivePermissions', $authorization->permissionsFor($user));

        return new UserResource($user);
    }
}
