<?php

namespace App\Http\Resources\Faq;

use Illuminate\Http\Resources\Json\JsonResource;

class FaqArticleResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->public_id,
            'category_id' => $this->category ? $this->category->public_id : null,
            'title' => $this->title,
            'slug' => $this->slug,
            'content' => $this->content, // Assuming rich text or markdown
            'tags' => $this->tags,
            'is_published' => (bool) $this->is_published,
            'views' => (int) $this->views,
            'helpful_count' => (int) $this->helpful_count,
            'unhelpful_count' => (int) $this->unhelpful_count,
            'comments_count' => (int) $this->comments_count,
            'category' => new FaqCategoryResource($this->whenLoaded('category')),
            'comments' => $this->whenLoaded('comments', function () {
                return FaqCommentResource::collection($this->comments);
            }),
            'author' => $this->whenLoaded('author', function () {
                return [
                    'id' => $this->author->id,
                    'name' => $this->author->name,
                    'avatar' => $this->author->avatar_url, // assuming user has this accessor
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'attachments' => $this->getMedia('attachments')->map(function ($media) {
                return [
                    'id' => $media->id,
                    'file_name' => $media->file_name, // Original name (or obfuscated if we did that, but for attachments we might want to keep original or display name)
                    // Actually we obfuscated filenames on upload. We should probably store a "display_name" as custom property if we want kindness.
                    // But for now, let's just return the media object properties.
                    'name' => $media->custom_properties['original_filename'] ?? $media->file_name,
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    'download_url' => \Illuminate\Support\Facades\URL::signedRoute('api.media.secure-download', ['media' => $media->id], now()->addMinutes(60)),
                ];
            }),
        ];
    }
}
