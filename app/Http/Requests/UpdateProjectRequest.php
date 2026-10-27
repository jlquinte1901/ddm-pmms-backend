<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'project_category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('project_categories', 'id')->where('is_active', true),
            ],
            'implementation_year' => ['sometimes', 'required', 'integer', 'between:1900,2100'],
            'project_name' => ['sometimes', 'required', 'string', 'max:255'],
            'contract_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('projects', 'contract_number')->ignore($this->route('pmmsProject')),
            ],
            'location' => ['sometimes', 'required', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'lgu' => ['sometimes', 'nullable', 'string', 'max:100'],
            'department' => ['sometimes', 'nullable', 'string', 'max:100'],
            'implementing_office' => ['sometimes', 'nullable', 'string', 'max:150'],
            'project_manager' => ['sometimes', 'nullable', 'string', 'max:100'],
            'fund_source' => ['sometimes', 'required', 'string', 'max:100'],
            'contract_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'revised_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'planned_start_date' => ['sometimes', 'required', 'date'],
            'planned_finish_date' => ['sometimes', 'required', 'date'],
            'actual_start_date' => ['sometimes', 'nullable', 'date'],
            'actual_finish_date' => ['sometimes', 'nullable', 'date'],
            'contractor' => ['sometimes', 'nullable', 'string', 'max:150'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $project = $this->route('pmmsProject');

            if (! $project instanceof Project) {
                return;
            }

            $plannedStart = $this->input('planned_start_date', $project->planned_start_date?->toDateString());
            $plannedFinish = $this->input('planned_finish_date', $project->planned_finish_date?->toDateString());

            if ($plannedStart !== null && $plannedFinish !== null && strtotime((string) $plannedFinish) < strtotime((string) $plannedStart)) {
                $validator->errors()->add('planned_finish_date', 'The planned finish date must not be before the planned start date.');
            }

            if ($project->status !== ProjectStatus::NotStarted) {
                foreach (['planned_start_date', 'planned_finish_date'] as $field) {
                    $current = $project->{$field}?->toDateString();

                    if ($this->has($field) && (string) $this->input($field) !== (string) $current) {
                        $validator->errors()->add($field, 'Planned dates can only be changed while the project is not started.');
                    }
                }
            }

            $actualStart = $this->input('actual_start_date', $project->actual_start_date?->toDateString());
            $actualFinish = $this->input('actual_finish_date', $project->actual_finish_date?->toDateString());

            if ($actualStart !== null && $actualFinish !== null && strtotime((string) $actualFinish) < strtotime((string) $actualStart)) {
                $validator->errors()->add('actual_finish_date', 'The actual finish date must not be before the actual start date.');
            }
        });
    }
}
