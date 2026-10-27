<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Database\Factories\ProjectUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only progress report. Progress is manually reported by the PE; it is
 * never inferred from dates.
 */
#[Fillable([
    'project_id',
    'report_date',
    'previous_progress',
    'progress_percentage',
    'accomplishment',
    'remarks',
])]
class ProjectUpdate extends Model
{
    use AppendOnly;

    /** @use HasFactory<ProjectUpdateFactory> */
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
            'report_date' => 'date',
            'previous_progress' => 'integer',
            'progress_percentage' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
