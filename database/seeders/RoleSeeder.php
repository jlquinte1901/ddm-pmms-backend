<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the four confirmed business roles.
     */
    public function run(): void
    {
        foreach (RoleName::cases() as $role) {
            Role::query()->updateOrCreate(
                ['name' => $role->value],
                ['label' => $role->label()],
            );
        }
    }
}
