<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePermissionRequest extends FormRequest
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
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:\.[a-z0-9-]+)+$/',
                'unique:permissions,name',
            ],
            'module' => ['required', 'string', Rule::in(['shared', 'ddm', 'pmms'])],
        ];
    }
}
