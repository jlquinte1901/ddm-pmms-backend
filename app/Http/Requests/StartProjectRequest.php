<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartProjectRequest extends FormRequest
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
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
