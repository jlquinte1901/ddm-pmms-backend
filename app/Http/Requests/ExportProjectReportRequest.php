<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FiltersProjects;
use Illuminate\Foundation\Http\FormRequest;

final class ExportProjectReportRequest extends FormRequest
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
        return $this->projectFilterRules();
    }
}
