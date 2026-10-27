<?php

namespace App\Http\Requests;

use App\Enums\DocumentAccessLevel;
use App\Enums\DocumentCategoryScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDocumentRequest extends FormRequest
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
            'category_id' => [
                'required',
                'integer',
                Rule::exists('document_categories', 'id')
                    ->where('scope', DocumentCategoryScope::Ddm->value)
                    ->where('is_active', true),
            ],
            'title' => ['required', 'string', 'max:255'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'document_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:10000'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'access_level' => ['nullable', Rule::enum(DocumentAccessLevel::class)],
            'file' => [
                'required',
                'file',
                'max:'.(int) config('documents.max_size_kb', 51200),
                'extensions:'.implode(',', config('documents.allowed_extensions', [])),
            ],
        ];
    }
}
