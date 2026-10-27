<?php

namespace App\Http\Resources;

use App\Models\DocumentDownloadRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentDownloadRequest */
final class DocumentDownloadRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'document' => $this->whenLoaded(
                'document',
                fn () => $this->document === null
                    ? null
                    : new DocumentSummaryResource($this->document),
            ),
            'requested_by' => $this->requested_by,
            'requester' => $this->whenLoaded(
                'requester',
                fn () => $this->requester === null
                    ? null
                    : new UserSummaryResource($this->requester),
            ),
            'decided_by' => $this->decided_by,
            'decider' => $this->whenLoaded(
                'decider',
                fn () => $this->decider === null
                    ? null
                    : new UserSummaryResource($this->decider),
            ),
            'status' => $this->status->value,
            'effective_status' => $this->effectiveStatus(),
            'is_lapsed' => $this->isLapsed(),
            'is_expired' => $this->isExpired(),
            'reason' => $this->reason,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
