<?php

namespace App\Support\MediaLibrary;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class UserAvatarPathGenerator implements PathGenerator
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

    /*
     * Get the base path for the media.
     * Structure: avatar/{user_uuid}/{media_id}
     */
    protected function getBasePath(Media $media): string
    {
        $model = $media->model;

        // Use public_id (UUID) if available, otherwise fallback to ID or 'unknown'
        $userUuid = $model && isset($model->public_id) ? $model->public_id : ($model->id ?? 'unknown');

        if ($media->collection_name === 'cover_photos') {
            return "avatar/cover_p/{$userUuid}/{$media->id}";
        }

        return "avatar/{$userUuid}/{$media->id}";
    }
}
