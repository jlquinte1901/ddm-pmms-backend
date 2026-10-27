<?php

namespace App\Services;

use App\Exceptions\StateConflictException;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DocumentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DocumentFileService $files,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, UploadedFile $file, User $actor): Document
    {
        $fileData = $this->files->inspect($file);
        $stored = $this->files->store($file, $fileData['extension']);

        try {
            $document = DB::transaction(function () use ($data, $fileData, $stored, $actor): Document {
                $document = new Document;
                $document->fill($data);
                $document->forceFill([
                    'created_by' => $actor->id,
                    'latest_version_number' => 1,
                ])->save();

                $version = new DocumentVersion;
                $version->fill([
                    'document_id' => $document->id,
                    'version_number' => 1,
                    'original_name' => $fileData['original_name'],
                    'storage_disk' => $stored['storage_disk'],
                    'storage_path' => $stored['storage_path'],
                    'mime_type' => $fileData['mime_type'],
                    'file_size' => $fileData['file_size'],
                    'checksum_sha256' => $fileData['checksum_sha256'],
                ]);
                $version->forceFill(['uploaded_by' => $actor->id])->save();

                $this->audit->record(
                    module: 'ddm',
                    action: 'document_created',
                    auditable: $document,
                    newValues: $this->snapshot($document),
                    context: [
                        'version_id' => $version->id,
                        'version_number' => 1,
                        'original_name' => $fileData['original_name'],
                        'mime_type' => $fileData['mime_type'],
                        'file_size' => $fileData['file_size'],
                        'checksum_sha256' => $fileData['checksum_sha256'],
                    ],
                    actor: $actor,
                );

                return $document;
            });
        } catch (Throwable $exception) {
            $this->files->delete($stored['storage_disk'], $stored['storage_path']);

            throw $exception;
        }

        return $document->load(['category', 'creator', 'latestVersion.uploader']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Document $document, array $data, User $actor): Document
    {
        return DB::transaction(function () use ($document, $data, $actor): Document {
            $document = Document::query()
                ->withoutProject()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $oldValues = $this->snapshot($document);
            $document->fill($data);
            $document->save();

            $this->audit->record(
                module: 'ddm',
                action: 'document_updated',
                auditable: $document,
                oldValues: $oldValues,
                newValues: $this->snapshot($document),
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    public function addVersion(
        Document $document,
        UploadedFile $file,
        ?string $changeNote,
        User $actor,
    ): DocumentVersion {
        if ($document->is_archived) {
            throw new StateConflictException('Restore the archived document before adding a new version.');
        }

        $fileData = $this->files->inspect($file);
        $stored = $this->files->store($file, $fileData['extension']);

        try {
            $version = DB::transaction(function () use ($document, $fileData, $stored, $changeNote, $actor): DocumentVersion {
                $document = Document::query()
                    ->withoutProject()
                    ->whereKey($document->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($document->is_archived) {
                    throw new StateConflictException('Restore the archived document before adding a new version.');
                }

                $currentVersion = $document->versions()
                    ->where('version_number', $document->latest_version_number)
                    ->first();

                if ($currentVersion !== null && hash_equals($currentVersion->checksum_sha256, $fileData['checksum_sha256'])) {
                    throw ValidationException::withMessages([
                        'file' => 'The uploaded file is identical to the current version.',
                    ]);
                }

                $previousVersionNumber = $document->latest_version_number;
                $nextVersionNumber = $previousVersionNumber + 1;

                $version = new DocumentVersion;
                $version->fill([
                    'document_id' => $document->id,
                    'version_number' => $nextVersionNumber,
                    'original_name' => $fileData['original_name'],
                    'storage_disk' => $stored['storage_disk'],
                    'storage_path' => $stored['storage_path'],
                    'mime_type' => $fileData['mime_type'],
                    'file_size' => $fileData['file_size'],
                    'checksum_sha256' => $fileData['checksum_sha256'],
                    'change_note' => $changeNote,
                ]);
                $version->forceFill(['uploaded_by' => $actor->id])->save();

                $document->forceFill(['latest_version_number' => $nextVersionNumber])->save();

                $this->audit->record(
                    module: 'ddm',
                    action: 'version_uploaded',
                    auditable: $document,
                    oldValues: ['latest_version_number' => $previousVersionNumber],
                    newValues: ['latest_version_number' => $nextVersionNumber],
                    context: [
                        'version_id' => $version->id,
                        'version_number' => $nextVersionNumber,
                        'original_name' => $fileData['original_name'],
                        'mime_type' => $fileData['mime_type'],
                        'file_size' => $fileData['file_size'],
                        'checksum_sha256' => $fileData['checksum_sha256'],
                    ],
                    actor: $actor,
                );

                return $version;
            });
        } catch (Throwable $exception) {
            $this->files->delete($stored['storage_disk'], $stored['storage_path']);

            throw $exception;
        }

        return $version->load('uploader');
    }

    public function archive(Document $document, User $actor): Document
    {
        return DB::transaction(function () use ($document, $actor): Document {
            $document = Document::query()
                ->withoutProject()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->is_archived) {
                throw new StateConflictException('The document is already archived.');
            }

            $oldValues = [
                'is_archived' => false,
                'archived_at' => null,
                'archived_by' => null,
            ];

            $document->forceFill([
                'is_archived' => true,
                'archived_at' => now(),
                'archived_by' => $actor->id,
            ])->save();

            $this->audit->record(
                module: 'ddm',
                action: 'document_archived',
                auditable: $document,
                oldValues: $oldValues,
                newValues: [
                    'is_archived' => true,
                    'archived_at' => $document->archived_at?->toIso8601String(),
                    'archived_by' => $actor->id,
                ],
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    public function unarchive(Document $document, User $actor): Document
    {
        return DB::transaction(function () use ($document, $actor): Document {
            $document = Document::query()
                ->withoutProject()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $document->is_archived) {
                throw new StateConflictException('The document is not archived.');
            }

            $oldValues = [
                'is_archived' => true,
                'archived_at' => $document->archived_at?->toIso8601String(),
                'archived_by' => $document->archived_by,
            ];

            $document->forceFill([
                'is_archived' => false,
                'archived_at' => null,
                'archived_by' => null,
            ])->save();

            $this->audit->record(
                module: 'ddm',
                action: 'document_unarchived',
                auditable: $document,
                oldValues: $oldValues,
                newValues: [
                    'is_archived' => false,
                    'archived_at' => null,
                    'archived_by' => null,
                ],
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    public function delete(Document $document, string $reason, User $actor): void
    {
        DB::transaction(function () use ($document, $reason, $actor): void {
            $document = Document::query()
                ->withoutProject()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->trashed()) {
                throw new StateConflictException('The document is already deleted.');
            }

            $document->delete();

            $this->audit->record(
                module: 'ddm',
                action: 'document_deleted',
                auditable: $document,
                oldValues: ['deleted_at' => null],
                newValues: ['deleted_at' => $document->deleted_at?->toIso8601String()],
                context: ['reason' => $reason],
                actor: $actor,
            );
        });
    }

    public function restore(Document $document, string $reason, User $actor): Document
    {
        return DB::transaction(function () use ($document, $reason, $actor): Document {
            $document = Document::withTrashed()
                ->withoutProject()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $document->trashed()) {
                throw new StateConflictException('The document is not deleted.');
            }

            $oldValues = ['deleted_at' => $document->deleted_at?->toIso8601String()];
            $document->restore();

            $this->audit->record(
                module: 'ddm',
                action: 'document_restored',
                auditable: $document,
                oldValues: $oldValues,
                newValues: ['deleted_at' => null],
                context: ['reason' => $reason],
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Document $document): array
    {
        return [
            'category_id' => $document->category_id,
            'title' => $document->title,
            'reference_no' => $document->reference_no,
            'document_date' => $document->document_date?->toDateString(),
            'description' => $document->description,
            'remarks' => $document->remarks,
            'access_level' => $document->access_level?->value,
        ];
    }
}
