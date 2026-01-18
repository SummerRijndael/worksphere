<?php

namespace App\Http\Requests\Faq;

use Illuminate\Foundation\Http\FormRequest;

class StoreFaqCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $content = $this->input('content') ?? '';
        $name = $this->input('name') ?? null;

        // Strip HTML tags
        $content = strip_tags($content);
        $name = $name ? strip_tags($name) : null;

        // Redact URLs (http, https, www)
        $urlPattern = '/\b(?:https?:\/\/|www\.)[^\s<>"\'\)]+/i';
        $content = preg_replace($urlPattern, '[link redacted]', $content);

        $this->merge([
            'content' => $content,
            'name' => $name,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'content' => 'required|string|max:5000',
            'recaptcha_token' => 'required|string',
            'recaptcha_v2_token' => 'nullable|string',
        ];
    }
}
