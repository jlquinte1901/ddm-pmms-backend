<?php

namespace App\Services;

use App\Exceptions\FileMissingException;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class DocumentDownloadService
{
    public function __construct(private readonly AuditService $audit) {}

    public function recordDeniedAttempt(Document $document, User $actor): void
    {
        $this->audit->record(
            module: 'ddm',
            action: 'document_download_denied',
            auditable: $document,
            context: [
                'access_level' => $document->effectiveAccessLevel()->value,
                'reason' => 'download_approval_required',
            ],
            actor: $actor,
        );
    }

    public function recordAndResolveVersion(Document $document, User $actor): DocumentVersion
    {
        $document = Document::query()
            ->withoutProject()
            ->with('category')
            ->whereKey($document->getKey())
            ->firstOrFail();

        $version = $document->versions()
            ->where('version_number', $document->latest_version_number)
            ->first();

        if ($version === null || ! Storage::disk($version->storage_disk)->exists($version->storage_path)) {
            throw new FileMissingException;
        }

        $this->audit->record(
            module: 'ddm',
            action: 'document_downloaded',
            auditable: $document,
            context: [
                'version_id' => $version->getKey(),
                'version_number' => $version->version_number,
                'original_name' => $version->original_name,
                'mime_type' => $version->mime_type,
                'file_size' => $version->file_size,
                'checksum_sha256' => $version->checksum_sha256,
                'access_level' => $document->effectiveAccessLevel()->value,
            ],
            actor: $actor,
        );

        return $version;
    }
}
