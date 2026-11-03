<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_role_backup', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('role_name')->nullable();
        });

        DB::table('migration_role_backup')->insertUsing(
            ['user_id', 'role_id', 'role_name'],
            DB::table('users')
                ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
                ->select('users.id', 'users.role_id', 'roles.name')
        );

        if (Schema::hasTable('permissions')) {
            $exists = DB::table('permissions')->where('name', 'ddm.downloads.direct')->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'name' => 'ddm.downloads.direct',
                    'module' => 'ddm',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('permission_role') && ! Schema::hasTable('permission_role_old')) {
            Schema::rename('permission_role', 'permission_role_old');
        }
        if (Schema::hasTable('permission_user') && ! Schema::hasTable('permission_user_old')) {
            Schema::rename('permission_user', 'permission_user_old');
        }
        if (Schema::hasTable('roles') && ! Schema::hasTable('roles_old')) {
            Schema::rename('roles', 'roles_old');
        }
        if (Schema::hasTable('permissions') && ! Schema::hasTable('permissions_old')) {
            Schema::rename('permissions', 'permissions_old');
        }

        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $teams = config('permission.teams') ?? false;

        if (! Schema::hasTable($tableNames['roles'])) {
            Schema::create($tableNames['roles'], static function (Blueprint $table) use ($teams, $columnNames) {
                $table->bigIncrements('id');
                if ($teams || config('permission.testing')) {
                    $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                    $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');
                }
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                if ($teams || config('permission.testing')) {
                    $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
                } else {
                    $table->unique(['name', 'guard_name']);
                }
            });
        }

        if (! Schema::hasTable($tableNames['permissions'])) {
            Schema::create($tableNames['permissions'], static function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        if (! Schema::hasTable($tableNames['model_has_permissions'])) {
            Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teams) {
                $table->unsignedBigInteger($pivotPermission);
                $table->string('model_type');
                $table->unsignedBigInteger($columnNames['model_morph_key']);
                $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');
                $table->foreign($pivotPermission)
                    ->references('id')
                    ->on($tableNames['permissions'])
                    ->onDelete('cascade');
                if ($teams || config('permission.testing')) {
                    $table->unsignedBigInteger($columnNames['team_foreign_key']);
                    $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');
                    $table->primary([$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                        'model_has_permissions_permission_model_type_primary');
                } else {
                    $table->primary([$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                        'model_has_permissions_permission_model_type_primary');
                }
            });
        }

        if (! Schema::hasTable($tableNames['model_has_roles'])) {
            Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $teams) {
                $table->unsignedBigInteger($pivotRole);
                $table->string('model_type');
                $table->unsignedBigInteger($columnNames['model_morph_key']);
                $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');
                $table->foreign($pivotRole)
                    ->references('id')
                    ->on($tableNames['roles'])
                    ->onDelete('cascade');
                if ($teams || config('permission.testing')) {
                    $table->unsignedBigInteger($columnNames['team_foreign_key']);
                    $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');
                    $table->primary([$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                        'model_has_roles_role_model_type_primary');
                } else {
                    $table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'],
                        'model_has_roles_role_model_type_primary');
                }
            });
        }

        if (! Schema::hasTable($tableNames['role_has_permissions'])) {
            Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
                $table->unsignedBigInteger($pivotPermission);
                $table->unsignedBigInteger($pivotRole);
                $table->foreign($pivotPermission)
                    ->references('id')
                    ->on($tableNames['permissions'])
                    ->onDelete('cascade');
                $table->foreign($pivotRole)
                    ->references('id')
                    ->on($tableNames['roles'])
                    ->onDelete('cascade');
                $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
            });
        }

        $guard = config('auth.defaults.guard', 'web');

        DB::table('roles_old')->get()->each(function ($oldRole) use ($guard) {
            DB::table('roles')->insert([
                'id' => $oldRole->id,
                'name' => $oldRole->name,
                'guard_name' => $guard,
                'created_at' => $oldRole->created_at ?? now(),
                'updated_at' => $oldRole->updated_at ?? now(),
            ]);
        });

        DB::table('permissions_old')->get()->each(function ($oldPerm) use ($guard) {
            DB::table('permissions')->insert([
                'id' => $oldPerm->id,
                'name' => $oldPerm->name,
                'guard_name' => $guard,
                'created_at' => $oldPerm->created_at ?? now(),
                'updated_at' => $oldPerm->updated_at ?? now(),
            ]);
        });
        if (! DB::table('permissions')->where('name', 'ddm.downloads.direct')->where('guard_name', $guard)->exists()) {
            DB::table('permissions')->insert([
                'name' => 'ddm.downloads.direct',
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('permission_role_old')->get()->each(function ($rp) {
            if (! DB::table('role_has_permissions')->where('permission_id', $rp->permission_id)->where('role_id', $rp->role_id)->exists()) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $rp->permission_id,
                    'role_id' => $rp->role_id,
                ]);
            }
        });

        DB::table('users')->whereNotNull('role_id')->get()->each(function ($user) {
            $role = DB::table('roles')->find($user->role_id);
            if ($role) {
                if (! DB::table('model_has_roles')->where('role_id', $role->id)->where('model_type', 'App\\Models\\User')->where('model_id', $user->id)->exists()) {
                    DB::table('model_has_roles')->insert([
                        'role_id' => $role->id,
                        'model_type' => 'App\\Models\\User',
                        'model_id' => $user->id,
                    ]);
                }
            }
        });

        if (Schema::hasTable('model_has_permissions') && ! Schema::hasColumn('model_has_permissions', 'granted_by')) {
            Schema::table('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('granted_by')->nullable()->after('model_id');
            });
        }
        DB::table('permission_user_old')->get()->each(function ($pu) {
            if (! DB::table('model_has_permissions')->where('permission_id', $pu->permission_id)->where('model_type', 'App\\Models\\User')->where('model_id', $pu->user_id)->exists()) {
                DB::table('model_has_permissions')->insert([
                    'permission_id' => $pu->permission_id,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $pu->user_id,
                    'granted_by' => $pu->granted_by ?? null,
                ]);
            }
        });

        if (Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                try {
                    $table->dropForeign(['role_id']);
                } catch (Throwable $e) {
                }
                $table->dropColumn('role_id');
            });
        }

        if (Schema::hasTable('document_categories')) {
            DB::table('document_categories')->where('default_access_level', 'open')->update(['default_access_level' => 'restricted']);
        }
        if (Schema::hasTable('documents')) {
            DB::table('documents')->where('access_level', 'open')->update(['access_level' => 'restricted']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('role_id')->nullable()->constrained('roles_old')->nullOnDelete();
            });
        }

        DB::table('migration_role_backup')->get()->each(function ($map) {
            if ($map->role_id) {
                DB::table('users')->where('id', $map->user_id)->update(['role_id' => $map->role_id]);
            }
        });

        $tableNames = config('permission.table_names');
        if (Schema::hasTable($tableNames['model_has_roles'])) {
            DB::table($tableNames['model_has_roles'])->truncate();
        }
        if (Schema::hasTable($tableNames['model_has_permissions'])) {
            DB::table($tableNames['model_has_permissions'])->truncate();
        }
        if (Schema::hasTable($tableNames['role_has_permissions'])) {
            DB::table($tableNames['role_has_permissions'])->truncate();
        }
        if (Schema::hasTable($tableNames['roles'])) {
            Schema::dropIfExists($tableNames['roles']);
        }
        if (Schema::hasTable($tableNames['permissions'])) {
            Schema::dropIfExists($tableNames['permissions']);
        }

        if (Schema::hasTable('permission_role_old') && ! Schema::hasTable('permission_role')) {
            Schema::rename('permission_role_old', 'permission_role');
        }
        if (Schema::hasTable('permission_user_old') && ! Schema::hasTable('permission_user')) {
            Schema::rename('permission_user_old', 'permission_user');
        }
        if (Schema::hasTable('roles_old') && ! Schema::hasTable('roles')) {
            Schema::rename('roles_old', 'roles');
        }
        if (Schema::hasTable('permissions_old') && ! Schema::hasTable('permissions')) {
            Schema::rename('permissions_old', 'permissions');
        }

        Schema::dropIfExists('migration_role_backup');
    }
};
