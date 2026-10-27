<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the confirmed permission catalogue.
     */
    public function run(): void
    {
        foreach (PermissionName::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['name' => $permission->value],
                ['module' => $permission->module()],
            );
        }
    }
}
