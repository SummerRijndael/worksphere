<?php

namespace App\Http\Requests\Support;

use App\Models\SupportSkill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportSkillRequest extends FormRequest
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
        /** @var SupportSkill|null $skill */
        $skill = $this->route('skill');

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
                'alpha_dash',
                Rule::unique('support_skills', 'slug')->ignore($skill?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
