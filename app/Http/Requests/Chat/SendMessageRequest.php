<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Use Policies for detailed authorization
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => 'required_without:media_ids|string|max:10000',
            'temp_id' => 'nullable|string|max:100',
            'reply_to_id' => 'nullable|string|exists:chat_messages,public_id',
            'media_ids' => 'nullable|array',
            'media_ids.*' => 'string|exists:media,id',
            'type' => 'nullable|string|in:text,system,image,video,file,audio,voice_clip',
        ];
    }
}
