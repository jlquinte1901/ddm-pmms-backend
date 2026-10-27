<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReopenProjectRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:2000'],
            'progress_percentage' => ['required', 'integer', 'between:0,99'],
        ];
    }
}
