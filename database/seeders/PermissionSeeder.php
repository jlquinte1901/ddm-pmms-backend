<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the confirmed permission catalogue.
     */
    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        foreach (PermissionName::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['name' => $permission->value, 'guard_name' => $guard],
                [],
            );
        }
    }
}
