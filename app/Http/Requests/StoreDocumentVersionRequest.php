<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDocumentVersionRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:'.(int) config('documents.max_size_kb', 51200),
                'extensions:'.implode(',', config('documents.allowed_extensions', [])),
            ],
            'change_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
