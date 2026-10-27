<?php

namespace App\Models;

use App\Enums\DocumentAccessLevel;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One table shared by DDM and PMMS. A document with no `project_id` belongs to
 * DDM; a document with a `project_id` is a PMMS supporting document.
 *
 * `latest_version_number` identifies the current version; the version row is
 * never overwritten. Lifecycle fields are changed only by named services.
 */
#[Fillable([
    'category_id',
    'title',
    'reference_no',
    'document_date',
    'description',
    'remarks',
    'access_level',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

    public const STATE_ACTIVE = 'active';

    public const STATE_ARCHIVED = 'archived';

    public const STATE_DELETED = 'deleted';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'access_level' => DocumentAccessLevel::class,
            'latest_version_number' => 'integer',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function state(): string
    {
        if ($this->trashed()) {
            return self::STATE_DELETED;
        }

        return $this->is_archived ? self::STATE_ARCHIVED : self::STATE_ACTIVE;
    }

    public function effectiveAccessLevel(): DocumentAccessLevel
    {
        return $this->access_level ?? $this->category->default_access_level;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /**
     * The current version is the row whose number equals
     * `documents.latest_version_number`.
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('version_number', 'max');
    }

    public function downloadRequests(): HasMany
    {
        return $this->hasMany(DocumentDownloadRequest::class);
    }

    #[Scope]
    protected function withoutProject(Builder $query): Builder
    {
        return $query->whereNull('project_id');
    }

    #[Scope]
    protected function forProject(Builder $query, Project|int $project): Builder
    {
        return $query->where('project_id', $project instanceof Project ? $project->id : $project);
    }

    #[Scope]
    protected function archived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    #[Scope]
    protected function notArchived(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }
}
