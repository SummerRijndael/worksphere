<?php

namespace App\Http\Requests\Faq;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFaqArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('article')) || $this->user()->can('faq.manage');
    }

    public function rules(): array
    {
        return [
            'category_id' => 'exists:faq_categories,public_id',
            'title' => 'string|max:255',
            'content' => 'sometimes|string',
            'is_published' => 'boolean',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'reason' => 'nullable|string',
        ];
    }
}
