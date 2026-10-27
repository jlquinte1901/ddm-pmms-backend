<?php

namespace App\Http\Resources;

use App\Models\ProjectHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectHistory */
final class ProjectHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'change_set_id' => $this->change_set_id,
            'action' => $this->action,
            'field_name' => $this->field_name,
            'previous_value' => $this->previous_value,
            'new_value' => $this->new_value,
            'reason' => $this->reason,
            'actor' => $this->whenLoaded('user', fn () => new UserSummaryResource($this->user)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
