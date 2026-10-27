<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateUserRequest extends FormRequest
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
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'password' => [
                'sometimes',
                'nullable',
                'string',
                'confirmed',
                Password::min((int) config('auth.password_min_length', 12)),
            ],
            'role_id' => ['sometimes', 'required', 'integer', 'exists:roles,id'],
            'section' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
