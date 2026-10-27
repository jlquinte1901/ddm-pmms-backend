<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectHistory>
 */
class ProjectHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'change_set_id' => (string) Str::uuid(),
            'action' => 'created',
            'field_name' => null,
            'previous_value' => null,
            'new_value' => ['project_name' => fake()->sentence(4)],
            'reason' => null,
        ];
    }
}
