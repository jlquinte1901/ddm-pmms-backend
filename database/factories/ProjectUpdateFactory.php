<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectUpdate>
 */
class ProjectUpdateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $previousProgress = fake()->numberBetween(0, 95);

        return [
            'project_id' => Project::factory(),
            'reported_by' => User::factory()->projectEngineer(),
            'report_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'previous_progress' => $previousProgress,
            'progress_percentage' => fake()->numberBetween($previousProgress, 100),
            'accomplishment' => fake()->optional()->sentence(),
            'remarks' => fake()->optional()->sentence(),
        ];
    }
}
