<?php

namespace Database\Factories;

use App\Enums\DownloadRequestStatus;
use App\Models\Document;
use App\Models\DocumentDownloadRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentDownloadRequest>
 */
class DocumentDownloadRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'requested_by' => User::factory(),
            'decided_by' => null,
            'status' => DownloadRequestStatus::Pending,
            'reason' => fake()->paragraph(),
            'decision_note' => null,
            'decided_at' => null,
            'expires_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DownloadRequestStatus::Approved,
            'decided_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function denied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DownloadRequestStatus::Denied,
            'decided_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DownloadRequestStatus::Approved,
            'decided_at' => now()->subDays(14),
            'expires_at' => now()->subDays(7),
        ]);
    }
}
