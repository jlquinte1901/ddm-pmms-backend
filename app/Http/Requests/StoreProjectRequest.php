<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProjectRequest extends FormRequest
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
                'required',
                'integer',
                Rule::exists('project_categories', 'id')->where('is_active', true),
            ],
            'implementation_year' => ['required', 'integer', 'between:1900,2100'],
            'project_name' => ['required', 'string', 'max:255'],
            'contract_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('projects', 'contract_number'),
            ],
            'location' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:100'],
            'lgu' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'implementing_office' => ['nullable', 'string', 'max:150'],
            'project_manager' => ['nullable', 'string', 'max:100'],
            'fund_source' => ['required', 'string', 'max:100'],
            'contract_amount' => ['required', 'numeric', 'min:0'],
            'revised_amount' => ['nullable', 'numeric', 'min:0'],
            'planned_start_date' => ['required', 'date'],
            'planned_finish_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
            'actual_start_date' => ['nullable', 'date'],
            'actual_finish_date' => array_values(array_filter([
                'nullable',
                'date',
                $this->filled('actual_start_date') ? 'after_or_equal:actual_start_date' : null,
            ])),
            'contractor' => ['nullable', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
