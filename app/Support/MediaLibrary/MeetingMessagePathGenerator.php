<?php

namespace App\Support\MediaLibrary;

use App\Models\MeetingMessage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class MeetingMessagePathGenerator implements PathGenerator
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

        if (! $model instanceof MeetingMessage) {
            return "meetings/unknown/unknown/{$media->uuid}";
        }

        $meetingPublicId = (string) ($model->meeting?->public_id ?? $model->meeting_id ?? 'unknown');
        $participantPublicId = (string) ($model->participant_public_id ?: 'unknown');

        return sprintf(
            'meetings/%s/%s/%s',
            $this->sanitizePathSegment($meetingPublicId),
            $this->sanitizePathSegment($participantPublicId),
            $media->uuid
        );
    }

    protected function sanitizePathSegment(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);

        return $sanitized !== null && $sanitized !== '' ? $sanitized : 'unknown';
    }
}
