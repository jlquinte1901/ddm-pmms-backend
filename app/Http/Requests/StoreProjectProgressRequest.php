<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreProjectProgressRequest extends FormRequest
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
            'report_date' => ['required', 'date', 'before_or_equal:today'],
            'progress_percentage' => ['required', 'integer', 'between:0,100'],
            'accomplishment' => ['nullable', 'string', 'max:10000'],
            'remarks' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $project = $this->route('pmmsProject');

            if (! $project instanceof Project || ! $this->filled('progress_percentage')) {
                return;
            }

            if ((int) $this->input('progress_percentage') < $project->progress_percentage && blank($this->input('remarks'))) {
                $validator->errors()->add('remarks', 'Remarks are required when progress is lowered.');
            }
        });
    }
}
