<?php

namespace App\Http\Resources;

use App\Models\ProjectUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectUpdate */
final class ProjectUpdateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_date' => $this->report_date?->toDateString(),
            'previous_progress' => $this->previous_progress,
            'progress_percentage' => $this->progress_percentage,
            'accomplishment' => $this->accomplishment,
            'remarks' => $this->remarks,
            'reporter' => $this->whenLoaded('reporter', fn () => new UserSummaryResource($this->reporter)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
