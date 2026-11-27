<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReassignProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The Project Service re-validates that the target is an active Project
     * Engineer (Spatie role), so this request only checks the user exists and
     * is active. `users.role_id` no longer exists after the Spatie migration.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'assigned_pe_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
