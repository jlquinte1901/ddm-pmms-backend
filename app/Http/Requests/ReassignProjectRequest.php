<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReassignProjectRequest extends FormRequest
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
        $projectEngineerRoleId = Role::query()
            ->where('name', RoleName::ProjectEngineer->value)
            ->value('id');

        return [
            'assigned_pe_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) use ($projectEngineerRoleId): void {
                    $query->where('role_id', $projectEngineerRoleId)
                        ->where('is_active', true)
                        ->whereNull('deleted_at');
                }),
            ],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
