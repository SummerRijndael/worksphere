<?php

namespace App\Http\Requests\TeamRole;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $team = $this->route('team');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('team_roles')->where(function ($query) use ($team) {
                    return $query->where('team_id', $team->id)->whereNull('deleted_at');
                }),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'in:primary,secondary,success,warning,error,info'],
            'level' => ['nullable', 'integer', 'min:1', 'max:99'],
            'is_default' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A role with this name already exists in this team.',
            'level.min' => 'Role level must be at least 1.',
            'level.max' => 'Role level cannot exceed 99 (reserved for system roles).',
        ];
    }
}
