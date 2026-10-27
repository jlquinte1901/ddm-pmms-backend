<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plannedStart = fake()->dateTimeBetween('-6 months', 'now');
        $plannedFinish = (clone $plannedStart)->modify('+6 months');

        return [
            'project_category_id' => ProjectCategory::factory(),
            'assigned_pe_id' => User::factory()->projectEngineer(),
            'created_by' => fn (array $attributes): int => $attributes['assigned_pe_id'],
            'implementation_year' => (int) $plannedStart->format('Y'),
            'project_name' => fake()->sentence(4),
            'contract_number' => fake()->optional()->bothify('CN-####-????'),
            'location' => fake()->city(),
            'region' => fake()->optional()->randomElement(['Region III', 'Region IV-A']),
            'lgu' => fake()->optional()->city(),
            'department' => fake()->optional()->randomElement(['City Engineering Office']),
            'implementing_office' => fake()->optional()->randomElement(['City Engineering Office']),
            'project_manager' => fake()->optional()->name(),
            'fund_source' => fake()->randomElement(['LGU', 'National Government', 'Special Account']),
            'contract_amount' => fake()->randomFloat(2, 100_000, 50_000_000),
            'revised_amount' => null,
            'planned_start_date' => $plannedStart->format('Y-m-d'),
            'planned_finish_date' => $plannedFinish->format('Y-m-d'),
            'actual_start_date' => null,
            'actual_finish_date' => null,
            'contractor' => fake()->optional()->company(),
            'status' => ProjectStatus::NotStarted,
            'progress_percentage' => 0,
            'remarks' => null,
        ];
    }

    public function ongoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Ongoing,
            'actual_start_date' => now()->subMonth()->toDateString(),
            'progress_percentage' => fake()->numberBetween(1, 99),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Completed,
            'progress_percentage' => 100,
            'completed_at' => now(),
            'actual_start_date' => now()->subMonths(6)->toDateString(),
            'actual_finish_date' => now()->toDateString(),
        ]);
    }

    public function paused(ProjectStatus $status = ProjectStatus::Suspended): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'paused_since' => now()->toDateString(),
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Terminated,
        ]);
    }

    public function ownedBy(User $projectEngineer): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_pe_id' => $projectEngineer->id,
            'created_by' => $projectEngineer->id,
        ]);
    }
}
