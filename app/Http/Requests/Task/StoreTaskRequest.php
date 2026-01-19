<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'parent_id' => ['nullable', 'string', 'exists:tasks,public_id'],
            'task_template_id' => ['nullable', 'string', 'exists:task_templates,public_id'],
            'assigned_to' => ['nullable', 'string', 'exists:users,public_id'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'due_date' => ['nullable', 'date'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'checklist' => ['nullable', 'array'],
            'checklist.*.text' => ['required_with:checklist', 'string', 'max:255'],
            'checklist.*.is_completed' => ['sometimes', 'boolean'],
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
            'title.required' => 'Task title is required.',
            'title.max' => 'Task title cannot exceed 255 characters.',
            'checklist.*.text.required_with' => 'Checklist items must have text.',
            'parent_id.exists' => 'The selected parent task does not exist.',
            'task_template_id.exists' => 'The selected task template does not exist.',
            'assigned_to.exists' => 'The selected assignee does not exist.',
            'priority.min' => 'Priority must be at least 1.',
            'priority.max' => 'Priority cannot exceed 5.',
            'estimated_hours.max' => 'Estimated hours cannot exceed 9999.99.',
        ];
    }
}
