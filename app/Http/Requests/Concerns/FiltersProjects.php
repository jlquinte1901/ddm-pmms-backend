<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ProjectIndicator;
use App\Enums\ProjectStatus;
use Illuminate\Validation\Rule;

trait FiltersProjects
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function projectFilterRules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'implementation_year' => ['sometimes', 'integer', 'between:1900,2100'],
            'project_category_id' => [
                'sometimes',
                'integer',
                Rule::exists('project_categories', 'id'),
            ],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'indicator' => ['sometimes', Rule::enum(ProjectIndicator::class)],
            'assigned_pe_id' => ['sometimes', 'integer', 'min:1'],
            'contractor' => ['sometimes', 'string', 'max:150'],
            'fund_source' => ['sometimes', 'string', 'max:100'],
            'region' => ['sometimes', 'string', 'max:100'],
            'lgu' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
