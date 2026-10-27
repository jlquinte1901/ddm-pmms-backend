<?php

namespace App\Http\Requests;

use App\Enums\DocumentAccessLevel;
use App\Enums\DocumentCategoryScope;
use App\Models\Document;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDocumentRequest extends FormRequest
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
        $document = $this->route('ddmDocument');
        $currentCategoryId = $document instanceof Document ? $document->category_id : null;

        return [
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('document_categories', 'id')
                    ->where('scope', DocumentCategoryScope::Ddm->value)
                    ->where(function (Builder $query) use ($currentCategoryId): void {
                        $query->where('is_active', true);

                        if ($currentCategoryId !== null) {
                            $query->orWhere('id', $currentCategoryId);
                        }
                    }),
            ],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'document_date' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'access_level' => ['sometimes', 'nullable', Rule::enum(DocumentAccessLevel::class)],
        ];
    }
}
