<?php

namespace App\Http\Requests;

use App\Enums\TaskChecklistItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskChecklistItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $item = $this->route('checklistItem');
        $task = $item?->task;

        if (! $task) {
            return false;
        }

        // If changing status, only assignee can do that
        if ($this->has('status')) {
            return $task->isAssignedTo($this->user());
        }

        // For other updates (text, position), user just needs update permission
        // For other updates (text, position), controller handles permission check
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
            'text' => ['sometimes', 'string', 'min:1', 'max:500', 'regex:/^[\p{L}\p{N}\p{P}\p{S}\s]+$/u'],
            'status' => ['sometimes', Rule::enum(TaskChecklistItemStatus::class)],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.min' => 'The checklist item text cannot be empty.',
            'text.max' => 'The checklist item text must not exceed 500 characters.',
            'text.regex' => 'The checklist item text contains invalid characters.',
            'status.Illuminate\Validation\Rules\Enum' => 'The status must be one of: todo, in_progress, on_hold, done.',
        ];
    }
}
