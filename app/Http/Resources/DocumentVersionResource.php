<?php

namespace App\Http\Resources;

use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentVersion */
final class DocumentVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'version_number' => $this->version_number,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'checksum_sha256' => $this->checksum_sha256,
            'change_note' => $this->change_note,
            'uploaded_by' => $this->uploaded_by,
            'uploader' => $this->whenLoaded('uploader', fn () => new UserSummaryResource($this->uploader)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
