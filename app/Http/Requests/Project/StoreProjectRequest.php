<?php

namespace App\Http\Requests\Project;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'priority' => ['sometimes', Rule::enum(ProjectPriority::class)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'client_id' => ['nullable', 'string', 'exists:clients,public_id'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'settings' => ['nullable', 'array'],
            'members' => ['nullable', 'array'],
            'members.*.user_id' => ['required_with:members', 'string', 'exists:users,public_id'],
            'members.*.role' => ['sometimes', 'string', 'in:manager,member,viewer'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Project name is required.',
            'name.max' => 'Project name cannot exceed 255 characters.',
            'due_date.after_or_equal' => 'Due date must be on or after the start date.',
            'client_id.exists' => 'The selected client does not exist.',
            'budget.max' => 'Budget cannot exceed the maximum allowed value.',
            'members.*.user_id.exists' => 'One or more selected team members do not exist.',
        ];
    }
}
