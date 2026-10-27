<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IndexUserRequest extends FormRequest
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
            'search' => ['sometimes', 'string', 'max:100'],
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'is_active' => ['sometimes', 'boolean'],
            'trashed' => ['sometimes', 'boolean'],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.(int) config('api.pagination.max', 100),
            ],
        ];
    }
}
