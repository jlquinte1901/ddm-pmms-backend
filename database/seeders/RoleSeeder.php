<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Seed the four confirmed business roles.
     */
    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        foreach (RoleName::cases() as $role) {
            Role::query()->updateOrCreate(
                ['name' => $role->value, 'guard_name' => $guard],
                [],
            );
        }
    }
}
