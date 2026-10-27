<?php

namespace App\Http\Requests;

use App\Enums\DocumentAccessLevel;
use App\Enums\DocumentCategoryScope;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexDocumentRequest extends FormRequest
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
            'q' => ['sometimes', 'string', 'max:100'],
            'category_id' => [
                'sometimes',
                'integer',
                Rule::exists('document_categories', 'id')
                    ->where('scope', DocumentCategoryScope::Ddm->value),
            ],
            'access_level' => ['sometimes', Rule::enum(DocumentAccessLevel::class)],
            'state' => [
                'sometimes',
                Rule::in([
                    Document::STATE_ACTIVE,
                    Document::STATE_ARCHIVED,
                    Document::STATE_DELETED,
                    'all',
                ]),
            ],
            'uploader' => ['sometimes', 'integer', 'min:1'],
            'reference_no' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => array_values(array_filter([
                'sometimes',
                'date',
                $this->filled('date_from') ? 'after_or_equal:date_from' : null,
            ])),
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.(int) config('api.pagination.max', 100),
            ],
        ];
    }
}
