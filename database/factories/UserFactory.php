<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => fn (): int => $this->roleId(RoleName::Viewer),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password1234'),
            'section' => fake()->optional()->randomElement(['Data Management', 'Engineering', 'Administration']),
            'is_active' => true,
            'last_login_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->withRole(RoleName::SuperAdmin);
    }

    public function admin(): static
    {
        return $this->withRole(RoleName::Admin);
    }

    public function projectEngineer(): static
    {
        return $this->withRole(RoleName::ProjectEngineer);
    }

    public function viewer(): static
    {
        return $this->withRole(RoleName::Viewer);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    private function withRole(RoleName $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => $this->roleId($role),
        ]);
    }

    private function roleId(RoleName $role): int
    {
        return Role::query()->firstOrCreate(
            ['name' => $role->value],
            ['label' => $role->label()],
        )->id;
    }
}
