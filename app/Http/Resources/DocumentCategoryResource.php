<?php

namespace App\Http\Resources;

use App\Models\DocumentCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentCategory */
final class DocumentCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scope' => $this->scope->value,
            'default_access_level' => $this->default_access_level->value,
            'is_active' => $this->is_active,
            'documents_count' => $this->whenCounted('documents'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
