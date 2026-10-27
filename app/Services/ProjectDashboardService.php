<?php

namespace App\Services;

use App\Enums\ProjectIndicator;
use App\Enums\ProjectStatus;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ProjectDashboardService
{
    public function __construct(
        private readonly ProjectQueryService $queries,
        private readonly ProjectIndicatorCalculator $indicator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, ProjectFilter $filter, ?CarbonImmutable $today = null): array
    {
        $today ??= $this->indicator->today();
        $base = $this->queries->baseQuery($user, $filter, $today);

        $byIndicator = [];

        foreach (ProjectIndicator::cases() as $indicator) {
            $byIndicator[$indicator->value] = $this->queries->indicatorCount($user, $filter, $indicator, $today);
        }

        $statusRows = (clone $base)
            ->reorder()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = [];

        foreach (ProjectStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($statusRows[$status->value] ?? 0);
        }

        $categoryRows = (clone $base)
            ->toBase()
            ->reorder()
            ->join('project_categories', 'projects.project_category_id', '=', 'project_categories.id')
            ->select([
                'project_categories.id as category_id',
                'project_categories.name as category_name',
                DB::raw('count(*) as aggregate'),
            ])
            ->groupBy('project_categories.id', 'project_categories.name')
            ->orderByDesc('aggregate')
            ->get();

        $yearRows = (clone $base)
            ->toBase()
            ->reorder()
            ->select([
                'implementation_year',
                DB::raw('count(*) as aggregate'),
            ])
            ->groupBy('implementation_year')
            ->orderBy('implementation_year')
            ->get();

        $watchlist = $this->queries->watchlist($user, $filter, $today);

        return [
            'filters' => $filter->toArray(),
            'generated_at' => now()->toIso8601String(),
            'counts' => [
                'total' => (clone $base)->count(),
                'by_indicator' => $byIndicator,
                'by_status' => $byStatus,
            ],
            'totals' => [
                'contract_amount' => $this->money((clone $base)->sum('contract_amount')),
                'revised_amount' => $this->money((clone $base)->sum('revised_amount')),
            ],
            'average_progress' => round((float) ((clone $base)->avg('progress_percentage') ?? 0), 2),
            'by_category' => $categoryRows->map(fn ($row): array => [
                'category_id' => (int) $row->category_id,
                'category' => $row->category_name,
                'count' => (int) $row->aggregate,
            ])->values()->all(),
            'by_year' => $yearRows->map(fn ($row): array => [
                'implementation_year' => (int) $row->implementation_year,
                'count' => (int) $row->aggregate,
            ])->values()->all(),
            'watchlist' => $watchlist
                ->map(fn (Project $project): array => (new ProjectResource($project))->resolve())
                ->values()
                ->all(),
        ];
    }

    private function money(float|int|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
