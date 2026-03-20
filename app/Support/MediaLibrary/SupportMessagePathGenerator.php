<?php

namespace App\Support\MediaLibrary;

use App\Models\SupportMessage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class SupportMessagePathGenerator implements PathGenerator
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

        if (! $model instanceof SupportMessage) {
            return "support/unknown/unknown/{$media->uuid}";
        }

        $conversationPublicId = (string) ($model->conversation?->public_id ?? $model->conversation_id ?? 'unknown');
        $messagePublicId = (string) ($model->public_id ?: $model->id ?: 'unknown');

        return sprintf(
            'support/%s/%s/%s',
            $this->sanitizePathSegment($conversationPublicId),
            $this->sanitizePathSegment($messagePublicId),
            $media->uuid
        );
    }

    protected function sanitizePathSegment(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);

        return $sanitized !== null && $sanitized !== '' ? $sanitized : 'unknown';
    }
}
