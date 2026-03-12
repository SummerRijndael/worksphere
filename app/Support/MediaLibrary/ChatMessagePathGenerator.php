<?php

namespace App\Support\MediaLibrary;

use App\Models\Chat\ChatMessage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class ChatMessagePathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media).'/responsive-images/';
    }

    protected function getBasePath(Media $media): string
    {
        $model = $media->model;

        if (! $model instanceof ChatMessage) {
            return "chats/unknown/unknown/{$media->uuid}";
        }

        $chatPublicId = (string) ($model->chat?->public_id ?? $model->chat_id ?? 'unknown');
        $senderPublicId = (string) ($model->user?->public_id ?? $model->user_id ?? 'unknown');

        return sprintf(
            'chats/%s/%s/%s',
            $this->sanitizePathSegment($chatPublicId),
            $this->sanitizePathSegment($senderPublicId),
            $media->uuid
        );
    }

    protected function sanitizePathSegment(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);

        return $sanitized !== null && $sanitized !== '' ? $sanitized : 'unknown';
    }
}
