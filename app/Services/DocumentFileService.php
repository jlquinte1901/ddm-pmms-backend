<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

final class DocumentFileService
{
    /**
     * @return array{
     *     extension: string,
     *     original_name: string,
     *     mime_type: string,
     *     file_size: int,
     *     checksum_sha256: string
     * }
     */
    public function inspect(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = config('documents.allowed_extensions', []);

        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => 'The file type is not allowed.',
            ]);
        }

        $mimeType = MimeTypes::getDefault()->guessMimeType($file->getRealPath());
        $matchingExtensions = $mimeType !== null
            ? MimeTypes::getDefault()->getExtensions($mimeType)
            : [];

        if ($mimeType === null || ! in_array($extension, $matchingExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => 'The file content does not match its extension.',
            ]);
        }

        $fileSize = (int) $file->getSize();
        $maxSizeBytes = (int) config('documents.max_size_kb', 51200) * 1024;

        if ($fileSize <= 0) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is empty.',
            ]);
        }

        if ($fileSize > $maxSizeBytes) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is too large.',
            ]);
        }

        $checksum = hash_file('sha256', $file->getRealPath());

        if ($checksum === false) {
            throw new RuntimeException('The uploaded file could not be checksummed.');
        }

        return [
            'extension' => $extension,
            'original_name' => $this->sanitizeOriginalName($file->getClientOriginalName(), $extension),
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'checksum_sha256' => $checksum,
        ];
    }

    /**
     * @return array{storage_disk: string, storage_path: string}
     */
    public function store(UploadedFile $file, string $extension): array
    {
        $disk = (string) config('documents.disk', 'local');
        $path = 'documents/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        if ($stored === false) {
            throw new RuntimeException('The document file could not be stored.');
        }

        return [
            'storage_disk' => $disk,
            'storage_path' => $path,
        ];
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    private function sanitizeOriginalName(string $name, string $extension): string
    {
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            $name = 'document.'.$extension;
        }

        return mb_substr($name, 0, 255);
    }
}
