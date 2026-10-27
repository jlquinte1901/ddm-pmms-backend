<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * System-wide audit trail. Rows are append-only and keep only `created_at`.
 */
#[Fillable([
    'user_id',
    'module',
    'action',
    'auditable_type',
    'auditable_id',
    'old_values',
    'new_values',
    'context',
    'ip_address',
    'request_id',
])]
class AuditLog extends Model
{
    use AppendOnly;

    /** @use HasFactory<AuditLogFactory> */
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
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
