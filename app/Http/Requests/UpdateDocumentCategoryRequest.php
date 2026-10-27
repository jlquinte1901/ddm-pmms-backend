<?php

namespace App\Http\Requests;

use App\Enums\DocumentAccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDocumentCategoryRequest extends FormRequest
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
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('document_categories', 'name')->ignore($this->route('ddmCategory')),
            ],
            'default_access_level' => ['sometimes', 'required', Rule::enum(DocumentAccessLevel::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
