<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => DocumentCategory::factory(),
            'project_id' => null,
            'created_by' => User::factory(),
            'archived_by' => null,
            'title' => fake()->sentence(4),
            'reference_no' => fake()->optional()->bothify('REF-####-????'),
            'document_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'description' => fake()->optional()->paragraph(),
            'remarks' => null,
            'access_level' => null,
            'latest_version_number' => 1,
            'is_archived' => false,
            'archived_at' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->id,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_archived' => true,
            'archived_at' => now(),
        ]);
    }
}
