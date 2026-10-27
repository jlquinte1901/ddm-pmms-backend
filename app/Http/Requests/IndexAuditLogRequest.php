<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexAuditLogRequest extends FormRequest
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
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'module' => ['sometimes', 'string', Rule::in(['shared', 'ddm', 'pmms'])],
            'action' => ['sometimes', 'string', 'max:100'],
            'auditable_type' => ['sometimes', 'string', 'max:100'],
            'auditable_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.(int) config('api.pagination.max', 100),
            ],
        ];
    }
}
