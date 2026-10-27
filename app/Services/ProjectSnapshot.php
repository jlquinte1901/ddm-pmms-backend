<?php

namespace App\Services;

use App\Models\Project;
use BackedEnum;

/**
 * Normalized project values for history and audit rows. Money stays a decimal
 * string and dates stay YYYY-MM-DD; event timestamps use ISO 8601.
 */
final class ProjectSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function forProject(Project $project): array
    {
        return [
            'project_category_id' => $project->project_category_id,
            'assigned_pe_id' => $project->assigned_pe_id,
            'created_by' => $project->created_by,
            'implementation_year' => $project->implementation_year,
            'project_name' => $project->project_name,
            'contract_number' => $project->contract_number,
            'location' => $project->location,
            'region' => $project->region,
            'lgu' => $project->lgu,
            'department' => $project->department,
            'implementing_office' => $project->implementing_office,
            'project_manager' => $project->project_manager,
            'fund_source' => $project->fund_source,
            'contract_amount' => (string) $project->contract_amount,
            'revised_amount' => $project->revised_amount === null ? null : (string) $project->revised_amount,
            'planned_start_date' => $project->planned_start_date?->toDateString(),
            'planned_finish_date' => $project->planned_finish_date?->toDateString(),
            'revised_finish_date' => $project->revised_finish_date?->toDateString(),
            'actual_start_date' => $project->actual_start_date?->toDateString(),
            'actual_finish_date' => $project->actual_finish_date?->toDateString(),
            'contractor' => $project->contractor,
            'status' => $project->status->value,
            'progress_percentage' => $project->progress_percentage,
            'paused_since' => $project->paused_since?->toDateString(),
            'completed_at' => $project->completed_at?->toIso8601String(),
            'remarks' => $project->remarks,
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function changes(array $old, array $new): array
    {
        $changes = [];

        foreach ($new as $field => $newValue) {
            $oldValue = $old[$field] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$field] = [$oldValue, $newValue];
            }
        }

        return $changes;
    }

    public function value(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}
