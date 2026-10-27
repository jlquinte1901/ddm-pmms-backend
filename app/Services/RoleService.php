<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Exceptions\StateConflictException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RoleService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{name: string, label: string, permissions?: array<int, int>}  $data
     */
    public function create(array $data, User $actor): Role
    {
        $permissionIds = $data['permissions'] ?? [];
        unset($data['permissions']);

        return DB::transaction(function () use ($data, $permissionIds, $actor): Role {
            $role = Role::query()->create($data);
            $role->permissions()->sync($permissionIds);

            $this->audit->record(
                module: 'shared',
                action: 'role_created',
                auditable: $role,
                newValues: $this->snapshot($role),
                actor: $actor,
            );

            return $role->refresh();
        });
    }

    /**
     * @param  array{name?: string, label?: string}  $data
     */
    public function update(Role $role, array $data, User $actor): Role
    {
        if (RoleName::tryFrom($role->name) !== null
            && array_key_exists('name', $data)
            && $data['name'] !== $role->name) {
            throw new StateConflictException('System role names cannot be changed.');
        }

        $oldValues = $this->snapshot($role);

        return DB::transaction(function () use ($role, $data, $actor, $oldValues): Role {
            $role->fill($data)->save();

            $this->audit->record(
                module: 'shared',
                action: 'role_updated',
                auditable: $role,
                oldValues: $oldValues,
                newValues: $this->snapshot($role),
                actor: $actor,
            );

            return $role->refresh();
        });
    }

    public function delete(Role $role, User $actor): void
    {
        if (RoleName::tryFrom($role->name) !== null) {
            throw new StateConflictException('System roles cannot be deleted.');
        }

        if (User::withTrashed()->where('role_id', $role->getKey())->exists()) {
            throw new StateConflictException('The role is assigned to one or more users.');
        }

        $oldValues = $this->snapshot($role);

        DB::transaction(function () use ($role, $actor, $oldValues): void {
            $role->delete();

            $this->audit->record(
                module: 'shared',
                action: 'role_deleted',
                auditable: $role,
                oldValues: $oldValues,
                actor: $actor,
            );
        });
    }

    /**
     * @param  array<int, int>  $permissionIds
     */
    public function syncPermissions(Role $role, array $permissionIds, User $actor): Role
    {
        $oldPermissions = $role->permissions()
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $permissionIds = array_values(array_unique($permissionIds));
        $newPermissions = Permission::query()
            ->whereKey($permissionIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return DB::transaction(function () use ($role, $permissionIds, $actor, $oldPermissions, $newPermissions): Role {
            $role->permissions()->sync($permissionIds);

            $this->audit->record(
                module: 'shared',
                action: 'role_permissions_updated',
                auditable: $role,
                oldValues: ['permissions' => $oldPermissions],
                newValues: ['permissions' => $newPermissions],
                actor: $actor,
            );

            return $role->refresh();
        });
    }

    /**
     * @return array{name: string, label: string, permissions: array<int, string>}
     */
    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'label' => $role->label,
            'permissions' => $role->permissions()->orderBy('name')->pluck('name')->all(),
        ];
    }
}
