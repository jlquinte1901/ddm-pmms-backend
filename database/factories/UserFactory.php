<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

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
        return $this->afterCreating(function (User $user): void {
            $this->assignRole($user, RoleName::SuperAdmin);
        });
    }

    public function admin(): static
    {
        return $this->afterCreating(function (User $user): void {
            $this->assignRole($user, RoleName::Admin);
        });
    }

    public function projectEngineer(): static
    {
        return $this->afterCreating(function (User $user): void {
            $this->assignRole($user, RoleName::ProjectEngineer);
        });
    }

    public function viewer(): static
    {
        return $this->afterCreating(function (User $user): void {
            $this->assignRole($user, RoleName::Viewer);
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    private function assignRole(User $user, RoleName $role): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $roleModel = Role::query()->firstOrCreate(
            ['name' => $role->value, 'guard_name' => $guard],
            [],
        );
        $user->syncRoles([$roleModel]);
    }
}
