<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePermissionRequest extends FormRequest
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
                'regex:/^[a-z0-9]+(?:\.[a-z0-9-]+)+$/',
                Rule::unique('permissions', 'name')->ignore($this->route('permission')),
            ],
            'module' => ['sometimes', 'required', 'string', Rule::in(['shared', 'ddm', 'pmms'])],
        ];
    }
}
