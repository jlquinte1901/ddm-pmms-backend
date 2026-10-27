<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DecideDocumentDownloadRequestRequest extends FormRequest
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
            'decision_note' => ['nullable', 'string', 'max:2000'],
            'approval_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.(int) config('documents.download.max_approval_days', 30),
            ],
        ];
    }
}
