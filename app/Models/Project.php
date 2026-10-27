<?php

namespace App\Models;

use App\Enums\ProjectIndicator;
use App\Enums\ProjectStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The monitoring indicator is never stored here. `status` is the lifecycle
 * state only, and `effective_finish_date` is a database-generated column.
 *
 * Ownership, lifecycle, progress, pause, completion, and revised-date fields
 * are intentionally excluded from mass assignment. Named service methods are
 * the only place allowed to change them.
 */
#[Fillable([
    'project_category_id',
    'implementation_year',
    'project_name',
    'contract_number',
    'location',
    'region',
    'lgu',
    'department',
    'implementing_office',
    'project_manager',
    'fund_source',
    'contract_amount',
    'revised_amount',
    'planned_start_date',
    'planned_finish_date',
    'actual_start_date',
    'actual_finish_date',
    'contractor',
    'remarks',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'implementation_year' => 'integer',
            'contract_amount' => 'decimal:2',
            'revised_amount' => 'decimal:2',
            'planned_start_date' => 'date',
            'planned_finish_date' => 'date',
            'revised_finish_date' => 'date',
            'effective_finish_date' => 'date',
            'actual_start_date' => 'date',
            'actual_finish_date' => 'date',
            'status' => ProjectStatus::class,
            'progress_percentage' => 'integer',
            'paused_since' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class, 'project_category_id');
    }

    public function assignedPe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_pe_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ProjectUpdate::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(ProjectHistory::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    #[Scope]
    protected function ownedBy(Builder $query, User|int $user): Builder
    {
        return $query->where('assigned_pe_id', $user instanceof User ? $user->id : $user);
    }

    /**
     * Apply the read-time monitoring indicator. The filter uses the stored
     * `effective_finish_date` generated column and takes today as a bound
     * parameter so the rule is testable and never stored.
     */
    #[Scope]
    protected function indicator(Builder $query, ProjectIndicator $indicator, CarbonImmutable $today): Builder
    {
        $todayDate = $today->toDateString();
        $criticalEndExclusive = $today->addDays(36)->toDateString();

        return match ($indicator) {
            ProjectIndicator::Completed => $query->where('status', ProjectStatus::Completed),
            ProjectIndicator::Terminated => $query->where('status', ProjectStatus::Terminated),
            ProjectIndicator::Paused => $query->whereIn('status', [
                ProjectStatus::Suspended,
                ProjectStatus::Postponed,
            ]),
            ProjectIndicator::Overdue => $query
                ->whereIn('status', [ProjectStatus::NotStarted, ProjectStatus::Ongoing])
                ->where('effective_finish_date', '<', $todayDate),
            ProjectIndicator::Critical => $query
                ->whereIn('status', [ProjectStatus::NotStarted, ProjectStatus::Ongoing])
                ->where('effective_finish_date', '>=', $todayDate)
                ->where('effective_finish_date', '<', $criticalEndExclusive),
            ProjectIndicator::Normal => $query
                ->whereIn('status', [ProjectStatus::NotStarted, ProjectStatus::Ongoing])
                ->where('effective_finish_date', '>=', $criticalEndExclusive),
        };
    }
}
