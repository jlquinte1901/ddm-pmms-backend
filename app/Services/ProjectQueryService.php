<?php

namespace App\Services;

use App\Enums\ProjectIndicator;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds every PMMS read query from the same filter object so list,
 * dashboard, and report numbers stay consistent.
 */
final class ProjectQueryService
{
    private const SORTABLE = [
        'project_name',
        'implementation_year',
        'contract_amount',
        'progress_percentage',
        'status',
        'planned_start_date',
        'planned_finish_date',
        'effective_finish_date',
        'created_at',
    ];

    public function __construct(
        private readonly ProjectAccessService $access,
        private readonly ProjectIndicatorCalculator $indicator,
    ) {}

    public function baseQuery(
        User $user,
        ProjectFilter $filter,
        ?CarbonImmutable $today = null,
    ): Builder {
        $today ??= $this->indicator->today();

        $query = $this->access
            ->visibleQuery($user)
            ->with(['category', 'assignedPe', 'creator']);

        return $filter->apply($query, $today, $this->access->canViewAll($user));
    }

    public function paginate(
        User $user,
        ProjectFilter $filter,
        string $sort,
        string $direction,
        int $perPage,
        ?CarbonImmutable $today = null,
    ): LengthAwarePaginator {
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        return $this->baseQuery($user, $filter, $today)
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Critical, overdue, and paused projects, closest effective finish date
     * first. The query reuses the shared filter and the caller's scope.
     *
     * @return Collection<int, Project>
     */
    public function watchlist(
        User $user,
        ProjectFilter $filter,
        ?CarbonImmutable $today = null,
    ): Collection {
        $today ??= $this->indicator->today();
        $criticalEndExclusive = $today->addDays(36)->toDateString();

        return $this->baseQuery($user, $filter, $today)
            ->where(function (Builder $query) use ($criticalEndExclusive): void {
                $query->where(function (Builder $query) use ($criticalEndExclusive): void {
                    $query->whereIn('status', [ProjectStatus::NotStarted, ProjectStatus::Ongoing])
                        ->where('effective_finish_date', '<', $criticalEndExclusive);
                })->orWhereIn('status', [ProjectStatus::Suspended, ProjectStatus::Postponed]);
            })
            ->orderBy('effective_finish_date')
            ->orderBy('id')
            ->limit((int) config('pmms.dashboard.watchlist_limit', 20))
            ->get();
    }

    public function indicatorCount(
        User $user,
        ProjectFilter $filter,
        ProjectIndicator $indicator,
        ?CarbonImmutable $today = null,
    ): int {
        return $this->baseQuery($user, $filter, $today)->indicator($indicator, $today ?? $this->indicator->today())->count();
    }
}
