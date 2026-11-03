<?php

namespace App\Services;

use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Resolves a user's effective permissions from the database on every call.
 * A permission comes from either the user's role or a direct grant.
 */
final class AuthorizationService
{
    /**
     * @return Collection<int, Permission>
     */
    public function permissionsFor(User $user): Collection
    {
        $user->loadMissing('roles', 'permissions');

        return $user->getAllPermissions()->sortBy('name')->values();
    }

    public function hasPermission(User $user, PermissionName|string $permission): bool
    {
        $name = $permission instanceof PermissionName ? $permission->value : $permission;

        return $user->hasPermissionTo($name);
    }
}
