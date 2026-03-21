<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertSupportSkillMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_public_id' => ['required', 'string', 'exists:users,public_id'],
            'membership_role' => ['required', 'string', Rule::in(['team_lead', 'sme', 'qa', 'agent'])],
            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
