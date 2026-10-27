<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
final class DocumentSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'reference_no' => $this->reference_no,
            'effective_access_level' => $this->effectiveAccessLevel()->value,
            'state' => $this->state(),
            'category' => $this->whenLoaded(
                'category',
                fn () => $this->category === null
                    ? null
                    : new DocumentCategoryResource($this->category),
            ),
        ];
    }
}
