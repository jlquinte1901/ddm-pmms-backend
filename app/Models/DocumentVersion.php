<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable document version. Rows are never updated or deleted, and the
 * private `storage_path` must never be exposed by the API.
 */
#[Fillable([
    'document_id',
    'version_number',
    'original_name',
    'storage_disk',
    'storage_path',
    'mime_type',
    'file_size',
    'checksum_sha256',
    'change_note',
])]
#[Hidden(['storage_path'])]
class DocumentVersion extends Model
{
    use AppendOnly;

    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'file_size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
