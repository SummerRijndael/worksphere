<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnforceTwoFactorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('users.manage_status') || $this->user()->hasRole('administrator');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enforce' => ['required', 'boolean'],
            'allowed_methods' => ['required_if:enforce,true', 'array', 'min:1'],
            'allowed_methods.*' => ['string', 'in:totp,sms,email,passkey'],
            'target_type' => ['required', 'string', 'in:user,role'],
            'target_id' => ['required', 'string'],
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
            'enforce.required' => 'Please specify whether to enforce or disable 2FA.',
            'allowed_methods.required_if' => 'At least one 2FA method must be allowed when enforcing.',
            'allowed_methods.min' => 'At least one 2FA method must be selected.',
            'allowed_methods.*.in' => 'Invalid 2FA method. Valid methods are: totp, sms, email, passkey.',
            'target_type.required' => 'Please specify the target type (user or role).',
            'target_type.in' => 'Target type must be either "user" or "role".',
            'target_id.required' => 'Please specify the target ID.',
        ];
    }
}
