<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRoleRequest extends FormRequest
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
                'max:50',
                'alpha_dash',
                Rule::unique('roles', 'name')->ignore($this->route('role')),
            ],
            'label' => ['sometimes', 'required', 'string', 'max:100'],
        ];
    }
}
