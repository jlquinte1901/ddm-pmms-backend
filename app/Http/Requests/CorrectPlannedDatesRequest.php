<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CorrectPlannedDatesRequest extends FormRequest
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
            'planned_start_date' => ['required', 'date'],
            'planned_finish_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
