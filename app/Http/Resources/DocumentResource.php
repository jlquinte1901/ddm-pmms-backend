<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
final class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->whenLoaded('category', fn () => new DocumentCategoryResource($this->category)),
            'title' => $this->title,
            'reference_no' => $this->reference_no,
            'document_date' => $this->document_date?->toDateString(),
            'description' => $this->description,
            'remarks' => $this->remarks,
            'access_level' => $this->access_level?->value,
            'effective_access_level' => $this->effectiveAccessLevel()->value,
            'state' => $this->state(),
            'is_archived' => $this->is_archived,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'archived_by' => $this->archived_by,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => new UserSummaryResource($this->creator)),
            'latest_version_number' => $this->latest_version_number,
            'latest_version' => $this->whenLoaded(
                'latestVersion',
                fn () => $this->latestVersion === null
                    ? null
                    : new DocumentVersionResource($this->latestVersion),
            ),
            'versions' => $this->whenLoaded(
                'versions',
                fn () => DocumentVersionResource::collection($this->versions),
            ),
            'can_download' => (bool) ($this->can_download ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
