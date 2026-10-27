<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FiltersProjects;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexProjectRequest extends FormRequest
{
    use FiltersProjects;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->projectFilterRules() + [
            'sort' => [
                'sometimes',
                Rule::in([
                    'project_name',
                    'implementation_year',
                    'contract_amount',
                    'progress_percentage',
                    'status',
                    'planned_start_date',
                    'planned_finish_date',
                    'effective_finish_date',
                    'created_at',
                ]),
            ],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.(int) config('api.pagination.max', 100),
            ],
        ];
    }
}
