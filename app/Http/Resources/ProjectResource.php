<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Services\ProjectIndicatorCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
final class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $calculator = app(ProjectIndicatorCalculator::class);
        $indicator = $calculator->indicator($this->resource);

        return [
            'id' => $this->id,
            'project_category_id' => $this->project_category_id,
            'category' => $this->whenLoaded('category', fn () => new ProjectCategoryResource($this->category)),
            'assigned_pe_id' => $this->assigned_pe_id,
            'assigned_pe' => $this->whenLoaded('assignedPe', fn () => new UserSummaryResource($this->assignedPe)),
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => new UserSummaryResource($this->creator)),
            'implementation_year' => $this->implementation_year,
            'project_name' => $this->project_name,
            'contract_number' => $this->contract_number,
            'location' => $this->location,
            'region' => $this->region,
            'lgu' => $this->lgu,
            'department' => $this->department,
            'implementing_office' => $this->implementing_office,
            'project_manager' => $this->project_manager,
            'fund_source' => $this->fund_source,
            'contract_amount' => (string) $this->contract_amount,
            'revised_amount' => $this->revised_amount === null ? null : (string) $this->revised_amount,
            'planned_start_date' => $this->planned_start_date?->toDateString(),
            'planned_finish_date' => $this->planned_finish_date?->toDateString(),
            'revised_finish_date' => $this->revised_finish_date?->toDateString(),
            'effective_finish_date' => $this->effective_finish_date?->toDateString(),
            'actual_start_date' => $this->actual_start_date?->toDateString(),
            'actual_finish_date' => $this->actual_finish_date?->toDateString(),
            'contractor' => $this->contractor,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'progress_percentage' => $this->progress_percentage,
            'paused_since' => $this->paused_since?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'remarks' => $this->remarks,
            'indicator' => $indicator->value,
            'indicator_label' => $indicator->label(),
            'days_remaining' => $calculator->daysRemaining($this->resource),
            'days_paused' => $calculator->daysPaused($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
