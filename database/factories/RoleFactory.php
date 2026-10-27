<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'label' => fake()->words(2, true),
        ];
    }

    public function named(RoleName $role): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $role->value,
            'label' => $role->label(),
        ]);
    }
}
