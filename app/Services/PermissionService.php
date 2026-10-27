<?php

namespace App\Services;

use App\Enums\PermissionName;
use App\Exceptions\StateConflictException;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PermissionService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{name: string, module: string}  $data
     */
    public function create(array $data, User $actor): Permission
    {
        return DB::transaction(function () use ($data, $actor): Permission {
            $permission = Permission::query()->create($data);

            $this->audit->record(
                module: 'shared',
                action: 'permission_created',
                auditable: $permission,
                newValues: $this->snapshot($permission),
                actor: $actor,
            );

            return $permission;
        });
    }

    /**
     * @param  array{name?: string, module?: string}  $data
     */
    public function update(Permission $permission, array $data, User $actor): Permission
    {
        if (PermissionName::tryFrom($permission->name) !== null
            && (($data['name'] ?? $permission->name) !== $permission->name
                || ($data['module'] ?? $permission->module) !== $permission->module)) {
            throw new StateConflictException('System permissions cannot be changed.');
        }

        $oldValues = $this->snapshot($permission);

        return DB::transaction(function () use ($permission, $data, $actor, $oldValues): Permission {
            $permission->fill($data)->save();

            $this->audit->record(
                module: 'shared',
                action: 'permission_updated',
                auditable: $permission,
                oldValues: $oldValues,
                newValues: $this->snapshot($permission),
                actor: $actor,
            );

            return $permission->refresh();
        });
    }

    public function delete(Permission $permission, User $actor): void
    {
        if (PermissionName::tryFrom($permission->name) !== null) {
            throw new StateConflictException('System permissions cannot be deleted.');
        }

        $oldValues = $this->snapshot($permission);

        DB::transaction(function () use ($permission, $actor, $oldValues): void {
            $permission->delete();

            $this->audit->record(
                module: 'shared',
                action: 'permission_deleted',
                auditable: $permission,
                oldValues: $oldValues,
                actor: $actor,
            );
        });
    }

    /**
     * @return array{name: string, module: string}
     */
    private function snapshot(Permission $permission): array
    {
        return [
            'name' => $permission->name,
            'module' => $permission->module,
        ];
    }
}
