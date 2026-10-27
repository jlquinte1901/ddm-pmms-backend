<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Database\Factories\ProjectHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only business history for one project. Field changes share a
 * `change_set_id`. Money is stored as a decimal string and dates as
 * YYYY-MM-DD inside the JSON values.
 */
#[Table('project_history')]
#[Fillable([
    'project_id',
    'user_id',
    'change_set_id',
    'action',
    'field_name',
    'previous_value',
    'new_value',
    'reason',
])]
class ProjectHistory extends Model
{
    use AppendOnly;

    /** @use HasFactory<ProjectHistoryFactory> */
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
            'previous_value' => 'array',
            'new_value' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
