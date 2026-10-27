<?php

namespace App\Models;

use App\Enums\DownloadRequestStatus;
use Database\Factories\DocumentDownloadRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A restricted or confidential download request. Whether an approval has
 * expired is derived from `expires_at`, never stored.
 */
#[Fillable(['document_id', 'reason'])]
class DocumentDownloadRequest extends Model
{
    /** @use HasFactory<DocumentDownloadRequestFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DownloadRequestStatus::class,
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isLapsed(): bool
    {
        if ($this->status !== DownloadRequestStatus::Pending || $this->created_at === null) {
            return false;
        }

        $lapseDays = (int) config('documents.download.pending_lapse_days', 14);

        return $this->created_at->lte(now()->subDays($lapseDays));
    }

    public function isExpired(): bool
    {
        if ($this->status !== DownloadRequestStatus::Approved || $this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lte(now());
    }

    public function effectiveStatus(): string
    {
        if ($this->isLapsed()) {
            return 'lapsed';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return $this->status->value;
    }

    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->where('status', DownloadRequestStatus::Pending);
    }

    #[Scope]
    protected function approved(Builder $query): Builder
    {
        return $query->where('status', DownloadRequestStatus::Approved);
    }
}
