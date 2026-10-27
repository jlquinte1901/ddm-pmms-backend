<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
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
            'uploaded_by' => User::factory(),
            'version_number' => 1,
            'original_name' => fake()->word().'.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'documents/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(1_000, 5_000_000),
            'checksum_sha256' => hash('sha256', fake()->uuid()),
            'change_note' => null,
        ];
    }
}
