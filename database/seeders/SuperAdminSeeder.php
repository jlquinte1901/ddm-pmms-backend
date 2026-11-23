<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Seeds the department Super Admin account used to sign in to DDM-PMMS.
 */
class SuperAdminSeeder extends Seeder
{
    /**
     * The account is keyed by email, so re-seeding repairs it without creating
     * duplicates or disturbing other users.
     */
    public function run(): void
    {
        $user = User::query()->withTrashed()->firstOrNew([
            'email' => 'superadmin@ced.com',
        ]);

        $user->forceFill([
            'full_name' => 'Data Management',
            'section' => 'Data Management',
            'password' => Hash::make('Superadmin@123'),
            'is_active' => true,
            'email_verified_at' => now(),
            'deleted_at' => null,
        ]);

        $user->save();

        $guard = config('auth.defaults.guard', 'web');
        $role = Role::query()->firstOrCreate([
            'name' => RoleName::SuperAdmin->value,
            'guard_name' => $guard,
        ]);

        $user->syncRoles([$role]);
    }
}
