<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class CompleteQaReviewRequest extends FormRequest
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
            'approved' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'results' => ['nullable', 'array'],
            'results.*.qa_check_item_id' => ['required_with:results', 'integer', 'exists:qa_check_items,id'],
            'results.*.passed' => ['required_with:results', 'boolean'],
            'results.*.notes' => ['nullable', 'string', 'max:1000'],
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
            'approved.required' => 'Please indicate whether the task is approved or rejected.',
            'approved.boolean' => 'The approval status must be true or false.',
            'results.*.qa_check_item_id.exists' => 'One or more QA check items do not exist.',
            'results.*.passed.required_with' => 'Each check result must indicate pass or fail.',
        ];
    }
}
