<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

class AssignSupportConversationSkillRequest extends FormRequest
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
            'support_skill_id' => ['nullable', 'string', 'exists:support_skills,public_id'],
        ];
    }
}
