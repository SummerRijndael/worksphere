<?php

namespace App\Http\Resources\Faq;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FaqCommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => strip_tags($this->user ? $this->user->name : ($this->name ?? 'Guest')),
            'content' => strip_tags($this->content),
            'created_at' => $this->created_at,
            'user_avatar' => $this->user ? $this->user->avatar_url : null,
        ];
    }
}
