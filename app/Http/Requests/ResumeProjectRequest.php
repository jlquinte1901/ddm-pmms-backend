<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResumeProjectRequest extends FormRequest
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
            'revised_finish_date' => ['required', 'date', 'after_or_equal:today'],
            'remarks' => ['required', 'string', 'max:2000'],
        ];
    }
}
