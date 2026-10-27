<?php

namespace App\Services;

use App\Enums\ProjectIndicator;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProjectReportService
{
    public const GROUP_BY = [
        'status',
        'indicator',
        'category',
        'implementation_year',
        'assigned_pe',
        'fund_source',
        'region',
        'lgu',
        'contractor',
    ];

    public function __construct(
        private readonly ProjectQueryService $queries,
        private readonly ProjectIndicatorCalculator $indicator,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(
        User $user,
        ProjectFilter $filter,
        string $groupBy,
        ?CarbonImmutable $today = null,
    ): array {
        $today ??= $this->indicator->today();
        $groupBy = in_array($groupBy, self::GROUP_BY, true) ? $groupBy : 'status';
        $base = $this->queries->baseQuery($user, $filter, $today);

        return [
            'filters' => $filter->toArray(),
            'group_by' => $groupBy,
            'totals' => $this->totals($base),
            'groups' => $this->groups($base, $groupBy, $today),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function export(
        User $user,
        ProjectFilter $filter,
        ?CarbonImmutable $today = null,
    ): StreamedResponse {
        $today ??= $this->indicator->today();
        $query = $this->queries
            ->baseQuery($user, $filter, $today)
            ->orderBy('id');

        $rowCount = (clone $query)->count();

        $this->audit->record(
            module: 'pmms',
            action: 'project_report_exported',
            newValues: [
                'format' => 'csv',
                'row_count' => $rowCount,
            ],
            context: ['filters' => $filter->toArray()],
            actor: $user,
        );

        $filename = 'project-report-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $today): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, [
                'Project ID',
                'Project Name',
                'Category',
                'Implementation Year',
                'Contract Number',
                'Location',
                'Region',
                'LGU',
                'Department',
                'Implementing Office',
                'Project Manager',
                'Fund Source',
                'Contract Amount',
                'Revised Amount',
                'Planned Start Date',
                'Planned Finish Date',
                'Revised Finish Date',
                'Effective Finish Date',
                'Actual Start Date',
                'Actual Finish Date',
                'Contractor',
                'Status',
                'Progress Percentage',
                'Assigned Project Engineer',
                'Indicator',
                'Days Remaining',
                'Days Paused',
                'Created At',
            ]);

            foreach ($query->lazy(200) as $project) {
                /** @var Project $project */
                $indicator = $this->indicator->indicator($project, $today);

                fputcsv($output, [
                    $project->getKey(),
                    $this->csvValue($project->project_name),
                    $this->csvValue($project->category?->name),
                    $project->implementation_year,
                    $this->csvValue($project->contract_number),
                    $this->csvValue($project->location),
                    $this->csvValue($project->region),
                    $this->csvValue($project->lgu),
                    $this->csvValue($project->department),
                    $this->csvValue($project->implementing_office),
                    $this->csvValue($project->project_manager),
                    $this->csvValue($project->fund_source),
                    (string) $project->contract_amount,
                    $project->revised_amount === null ? '' : (string) $project->revised_amount,
                    $project->planned_start_date?->toDateString(),
                    $project->planned_finish_date?->toDateString(),
                    $project->revised_finish_date?->toDateString(),
                    $project->effective_finish_date?->toDateString(),
                    $project->actual_start_date?->toDateString(),
                    $project->actual_finish_date?->toDateString(),
                    $this->csvValue($project->contractor),
                    $project->status->value,
                    $project->progress_percentage,
                    $this->csvValue($project->assignedPe?->full_name),
                    $indicator->value,
                    $this->indicator->daysRemaining($project, $today),
                    $this->indicator->daysPaused($project, $today),
                    $project->created_at?->toIso8601String(),
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(mixed $base): array
    {
        return [
            'count' => (clone $base)->count(),
            'contract_amount' => $this->money((clone $base)->sum('contract_amount')),
            'revised_amount' => $this->money((clone $base)->sum('revised_amount')),
            'average_progress' => round((float) ((clone $base)->avg('progress_percentage') ?? 0), 2),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groups(mixed $base, string $groupBy, CarbonImmutable $today): array
    {
        if ($groupBy === 'indicator') {
            return $this->indicatorGroups($base, $today);
        }

        if ($groupBy === 'status') {
            return $this->statusGroups($base);
        }

        if ($groupBy === 'category') {
            return $this->categoryGroups($base);
        }

        if ($groupBy === 'assigned_pe') {
            return $this->assignedPeGroups($base);
        }

        if ($groupBy === 'implementation_year') {
            return $this->scalarGroups($base, 'implementation_year');
        }

        $column = match ($groupBy) {
            'fund_source' => 'fund_source',
            'region' => 'region',
            'lgu' => 'lgu',
            'contractor' => 'contractor',
            default => 'status',
        };

        return $this->scalarGroups($base, $column);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function statusGroups(mixed $base): array
    {
        $rows = (clone $base)
            ->toBase()
            ->reorder()
            ->select([
                'status as group_key',
                DB::raw('count(*) as aggregate'),
                DB::raw('sum(contract_amount) as contract_total'),
                DB::raw('sum(revised_amount) as revised_total'),
                DB::raw('avg(progress_percentage) as average_progress'),
            ])
            ->groupBy('status')
            ->get()
            ->keyBy('group_key');

        $groups = [];

        foreach (ProjectStatus::cases() as $status) {
            $row = $rows->get($status->value);

            $groups[] = [
                'key' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($row->aggregate ?? 0),
                'contract_amount' => $this->money($row->contract_total ?? null),
                'revised_amount' => $this->money($row->revised_total ?? null),
                'average_progress' => round((float) ($row->average_progress ?? 0), 2),
            ];
        }

        return $groups;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function indicatorGroups(mixed $base, CarbonImmutable $today): array
    {
        $groups = [];

        foreach (ProjectIndicator::cases() as $indicator) {
            $query = (clone $base)->indicator($indicator, $today);

            $groups[] = [
                'key' => $indicator->value,
                'label' => $indicator->label(),
                'count' => (clone $query)->count(),
                'contract_amount' => $this->money((clone $query)->sum('contract_amount')),
                'revised_amount' => $this->money((clone $query)->sum('revised_amount')),
                'average_progress' => round((float) ((clone $query)->avg('progress_percentage') ?? 0), 2),
            ];
        }

        return $groups;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categoryGroups(mixed $base): array
    {
        $rows = (clone $base)
            ->toBase()
            ->reorder()
            ->join('project_categories', 'projects.project_category_id', '=', 'project_categories.id')
            ->select([
                'project_categories.id as group_key',
                'project_categories.name as group_label',
                DB::raw('count(*) as aggregate'),
                DB::raw('sum(contract_amount) as contract_total'),
                DB::raw('sum(revised_amount) as revised_total'),
                DB::raw('avg(progress_percentage) as average_progress'),
            ])
            ->groupBy('project_categories.id', 'project_categories.name')
            ->orderByDesc('aggregate')
            ->get();

        return $rows->map(fn ($row): array => [
            'key' => (int) $row->group_key,
            'label' => $row->group_label,
            'count' => (int) $row->aggregate,
            'contract_amount' => $this->money($row->contract_total),
            'revised_amount' => $this->money($row->revised_total),
            'average_progress' => round((float) $row->average_progress, 2),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function assignedPeGroups(mixed $base): array
    {
        $rows = (clone $base)
            ->toBase()
            ->reorder()
            ->join('users', 'projects.assigned_pe_id', '=', 'users.id')
            ->select([
                'users.id as group_key',
                'users.full_name as group_label',
                DB::raw('count(*) as aggregate'),
                DB::raw('sum(contract_amount) as contract_total'),
                DB::raw('sum(revised_amount) as revised_total'),
                DB::raw('avg(progress_percentage) as average_progress'),
            ])
            ->groupBy('users.id', 'users.full_name')
            ->orderByDesc('aggregate')
            ->get();

        return $rows->map(fn ($row): array => [
            'key' => (int) $row->group_key,
            'label' => $row->group_label,
            'count' => (int) $row->aggregate,
            'contract_amount' => $this->money($row->contract_total),
            'revised_amount' => $this->money($row->revised_total),
            'average_progress' => round((float) $row->average_progress, 2),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scalarGroups(mixed $base, string $column): array
    {
        $rows = (clone $base)
            ->toBase()
            ->reorder()
            ->select([
                $column.' as group_key',
                DB::raw('count(*) as aggregate'),
                DB::raw('sum(contract_amount) as contract_total'),
                DB::raw('sum(revised_amount) as revised_total'),
                DB::raw('avg(progress_percentage) as average_progress'),
            ])
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->get();

        return $rows->map(function ($row): array {
            $key = $row->group_key;

            return [
                'key' => $key,
                'label' => $key === null || $key === '' ? 'Unspecified' : (string) $key,
                'count' => (int) $row->aggregate,
                'contract_amount' => $this->money($row->contract_total),
                'revised_amount' => $this->money($row->revised_total),
                'average_progress' => round((float) $row->average_progress, 2),
            ];
        })->values()->all();
    }

    private function money(float|int|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) ($value ?? '');

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
