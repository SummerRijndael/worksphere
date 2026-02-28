<?php

namespace App\Support\MediaLibrary;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class TeamPathGenerator implements PathGenerator
{
    /*
     * Get the path for the given media, relative to the root storage path.
     */
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media).'/';
    }

    /*
     * Get the path for conversions of the given media, relative to the root storage path.
     */
    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media).'/conversions/';
    }

    /*
     * Get the path for responsive images of the given media, relative to the root storage path.
     */
    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media).'/responsive-images/';
    }

    protected function getBasePath(Media $media): string
    {
        $model = $media->model;

        // Use public_id (UUID) if available, otherwise fallback to ID
        $teamUuid = $model && isset($model->public_id) ? $model->public_id : ($model->id ?? 'unknown');

        if ($media->collection_name === 'avatars') {
            return "avatar/{$teamUuid}/{$media->id}";
        }

        return "private/{$teamUuid}/team_files/{$media->id}";
    }
}
