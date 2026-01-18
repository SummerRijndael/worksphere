<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('users.manage_status');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:active,pending,suspended,blocked,disabled'],
            'reason' => ['required_if:status,blocked,suspended', 'nullable', 'string', 'min:10', 'max:1000'],
            'suspended_until' => ['required_if:status,suspended', 'nullable', 'date', 'after:now'],
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
            'reason.required_if' => 'A reason is required when blocking or suspending a user.',
            'reason.min' => 'Please provide a more detailed reason (at least 10 characters).',
            'suspended_until.required_if' => 'A suspension end date is required when suspending a user.',
            'suspended_until.after' => 'The suspension end date must be in the future.',
        ];
    }
}
