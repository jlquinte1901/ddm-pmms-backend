<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FiltersProjects;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProjectReportRequest extends FormRequest
{
    use FiltersProjects;

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
            'group_by' => ['sometimes', Rule::in(self::GROUP_BY)],
        ];
    }
}
