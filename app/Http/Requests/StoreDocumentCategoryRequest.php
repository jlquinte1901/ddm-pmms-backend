<?php

namespace App\Http\Requests;

use App\Enums\DocumentAccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDocumentCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', Rule::unique('document_categories', 'name')],
            'default_access_level' => ['required', Rule::enum(DocumentAccessLevel::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
