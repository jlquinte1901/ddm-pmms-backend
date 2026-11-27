<?php

namespace App\Services;

use App\Enums\PermissionName;
use App\Exceptions\StateConflictException;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;

final class PermissionService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{name: string}  $data
     */
    public function create(array $data, User $actor): Permission
    {
        return DB::transaction(function () use ($data, $actor): Permission {
            $permission = Permission::query()->create([
                'name' => $data['name'],
                'guard_name' => Guard::getDefaultName(User::class),
            ]);

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
     * @param  array{name?: string}  $data
     */
    public function update(Permission $permission, array $data, User $actor): Permission
    {
        if (PermissionName::tryFrom($permission->name) !== null
            && array_key_exists('name', $data)
            && $data['name'] !== $permission->name) {
            throw new StateConflictException('System permissions cannot be changed.');
        }

        $oldValues = $this->snapshot($permission);

        return DB::transaction(function () use ($permission, $data, $actor, $oldValues): Permission {
            if (array_key_exists('name', $data)) {
                $permission->name = $data['name'];
            }

            $permission->save();

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
            // Delete through the query builder so Spatie's deleting listener
            // (which resolves users by guard) is skipped. FKs cascade the pivots.
            Permission::query()->whereKey($permission->getKey())->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();

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
