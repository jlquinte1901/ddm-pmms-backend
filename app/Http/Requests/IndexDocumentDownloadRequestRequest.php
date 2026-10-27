<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexDocumentDownloadRequestRequest extends FormRequest
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
            'status' => [
                'sometimes',
                Rule::in([
                    'pending',
                    'approved',
                    'denied',
                    'cancelled',
                    'lapsed',
                    'expired',
                ]),
            ],
            'document_id' => [
                'sometimes',
                'integer',
                Rule::exists('documents', 'id')->whereNull('project_id'),
            ],
            'requester' => ['sometimes', 'integer', 'min:1'],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.(int) config('api.pagination.max', 100),
            ],
        ];
    }
}
