<?php

namespace App\Services;

use App\Exceptions\StateConflictException;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UserService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{full_name: string, email: string, password: string, role_id: int, section?: string|null}  $data
     */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $user = new User;
            $user->forceFill([
                'full_name' => trim($data['full_name']),
                'email' => Str::lower(trim($data['email'])),
                'password' => $data['password'],
                'role_id' => $data['role_id'],
                'section' => $data['section'] ?? null,
                'is_active' => true,
            ])->save();

            $this->audit->record(
                module: 'shared',
                action: 'user_created',
                auditable: $user,
                newValues: $this->snapshot($user),
                actor: $actor,
            );

            return $user;
        });
    }

    /**
     * @param  array{full_name?: string, email?: string, password?: string|null, role_id?: int, section?: string|null}  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        $oldValues = $this->snapshot($user);
        $roleChanged = array_key_exists('role_id', $data) && (int) $data['role_id'] !== (int) $user->role_id;
        $passwordChanged = array_key_exists('password', $data) && filled($data['password']);

        if ($actor->is($user) && $roleChanged) {
            throw new StateConflictException('You cannot change your own role.');
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = Str::lower(trim($data['email']));
        }

        if (array_key_exists('full_name', $data)) {
            $data['full_name'] = trim($data['full_name']);
        }

        if (array_key_exists('password', $data) && blank($data['password'])) {
            unset($data['password']);
        }

        return DB::transaction(function () use ($user, $data, $actor, $oldValues, $roleChanged, $passwordChanged): User {
            $user->forceFill($data)->save();

            if ($roleChanged || $passwordChanged) {
                $user->tokens()->delete();
            }

            $this->audit->record(
                module: 'shared',
                action: 'user_updated',
                auditable: $user,
                oldValues: $oldValues,
                newValues: $this->snapshot($user),
                context: array_filter([
                    'role_changed' => $roleChanged,
                    'password_changed' => $passwordChanged,
                ]),
                actor: $actor,
            );

            return $user->refresh();
        });
    }

    public function deactivate(User $user, User $actor): User
    {
        if ($actor->is($user)) {
            throw new StateConflictException('You cannot deactivate your own account.');
        }

        if (! $user->is_active) {
            return $user;
        }

        $oldValues = $this->snapshot($user);

        return DB::transaction(function () use ($user, $actor, $oldValues): User {
            $user->forceFill(['is_active' => false])->save();
            $user->tokens()->delete();

            $this->audit->record(
                module: 'shared',
                action: 'user_deactivated',
                auditable: $user,
                oldValues: $oldValues,
                newValues: $this->snapshot($user),
                actor: $actor,
            );

            return $user->refresh();
        });
    }

    public function activate(User $user, User $actor): User
    {
        if ($user->is_active) {
            return $user;
        }

        $oldValues = $this->snapshot($user);

        return DB::transaction(function () use ($user, $actor, $oldValues): User {
            $user->forceFill(['is_active' => true])->save();

            $this->audit->record(
                module: 'shared',
                action: 'user_activated',
                auditable: $user,
                oldValues: $oldValues,
                newValues: $this->snapshot($user),
                actor: $actor,
            );

            return $user->refresh();
        });
    }

    public function delete(User $user, User $actor): void
    {
        if ($actor->is($user)) {
            throw new StateConflictException('You cannot delete your own account.');
        }

        $oldValues = $this->snapshot($user);

        DB::transaction(function () use ($user, $actor, $oldValues): void {
            $user->tokens()->delete();
            $user->delete();

            $this->audit->record(
                module: 'shared',
                action: 'user_deleted',
                auditable: $user,
                oldValues: $oldValues,
                newValues: ['deleted_at' => $user->deleted_at?->toIso8601String()],
                actor: $actor,
            );
        });
    }

    public function restore(User $user, User $actor): User
    {
        if (! $user->trashed()) {
            throw new StateConflictException('The user is not deleted.');
        }

        $oldValues = ['deleted_at' => $user->deleted_at?->toIso8601String()];

        return DB::transaction(function () use ($user, $actor, $oldValues): User {
            $user->restore();

            $this->audit->record(
                module: 'shared',
                action: 'user_restored',
                auditable: $user,
                oldValues: $oldValues,
                newValues: ['deleted_at' => null],
                actor: $actor,
            );

            return $user->refresh();
        });
    }

    /**
     * @param  array<int, int>  $permissionIds
     */
    public function syncPermissions(User $user, array $permissionIds, User $actor): User
    {
        $oldPermissions = $user->grantedPermissions()
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $permissionIds = array_values(array_unique($permissionIds));
        $newPermissions = Permission::query()
            ->whereKey($permissionIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return DB::transaction(function () use ($user, $permissionIds, $actor, $oldPermissions, $newPermissions): User {
            $grants = collect($permissionIds)
                ->mapWithKeys(fn (int $permissionId): array => [$permissionId => ['granted_by' => $actor->getKey()]])
                ->all();

            $user->grantedPermissions()->sync($grants);

            $this->audit->record(
                module: 'shared',
                action: 'user_permissions_updated',
                auditable: $user,
                oldValues: ['permissions' => $oldPermissions],
                newValues: ['permissions' => $newPermissions],
                actor: $actor,
            );

            return $user->refresh();
        });
    }

    /**
     * @return array{full_name: string, email: string, section: string|null, role_id: int, is_active: bool}
     */
    private function snapshot(User $user): array
    {
        return [
            'full_name' => $user->full_name,
            'email' => $user->email,
            'section' => $user->section,
            'role_id' => $user->role_id,
            'is_active' => $user->is_active,
        ];
    }
}
