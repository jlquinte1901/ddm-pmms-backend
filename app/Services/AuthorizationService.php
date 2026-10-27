<?php

namespace App\Services;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
        return Permission::query()
            ->where(function (Builder $query) use ($user): void {
                $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->whereKey($user->role_id))
                    ->orWhereHas('users', fn (Builder $userQuery) => $userQuery->whereKey($user->getKey()));
            })
            ->orderBy('name')
            ->get();
    }

    public function hasPermission(User $user, PermissionName|string $permission): bool
    {
        $name = $permission instanceof PermissionName ? $permission->value : $permission;

        return Permission::query()
            ->where('name', $name)
            ->where(function (Builder $query) use ($user): void {
                $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->whereKey($user->role_id))
                    ->orWhereHas('users', fn (Builder $userQuery) => $userQuery->whereKey($user->getKey()));
            })
            ->exists();
    }
}
