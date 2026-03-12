<?php

namespace App\Support\MediaLibrary;

use App\Models\MeetingRecording;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class MeetingRecordingPathGenerator implements PathGenerator
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

        if (! $model instanceof MeetingRecording) {
            return "meetings/unknown/{$media->uuid}";
        }

        $meetingPublicId = $model->meeting?->public_id;
        $meetingIdentifier = $meetingPublicId ?: (string) $model->meeting_id;

        return "meetings/{$meetingIdentifier}/{$media->uuid}";
    }
}
