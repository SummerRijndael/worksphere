<?php

namespace App\Http\Resources\Faq;

use Illuminate\Http\Resources\Json\JsonResource;

class FaqCategoryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->public_id, // Map public_id to id for seamless frontend usage
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'order' => $this->order,
            'is_public' => (bool) $this->is_public,
            'articles_count' => $this->articles_count ?? 0,
            'total_views' => (int) $this->total_views,
            'total_helpful' => (int) $this->total_helpful,
            'total_unhelpful' => (int) $this->total_unhelpful,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'articles' => FaqArticleResource::collection($this->whenLoaded('articles')),
            'author' => $this->whenLoaded('author', function () {
                return [
                    'id' => $this->author->id,
                    'name' => $this->author->name,
                    'avatar' => $this->author->avatar_url,
                ];
            }),
        ];
    }
}
