<?php

namespace Database\Factories;

use App\Enums\DocumentAccessLevel;
use App\Enums\DocumentCategoryScope;
use App\Models\DocumentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentCategory>
 */
class DocumentCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'scope' => DocumentCategoryScope::Ddm,
            'default_access_level' => DocumentAccessLevel::Open,
            'is_active' => true,
        ];
    }

    public function pmms(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => DocumentCategoryScope::Pmms,
        ]);
    }

    public function restricted(): static
    {
        return $this->state(fn (array $attributes) => [
            'default_access_level' => DocumentAccessLevel::Restricted,
        ]);
    }

    public function confidential(): static
    {
        return $this->state(fn (array $attributes) => [
            'default_access_level' => DocumentAccessLevel::Confidential,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
