<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaService
{
    public function __construct(
        protected FileSecurityValidator $fileValidator
    ) {}

    public function attach(Model $model, UploadedFile $file, string $collection, ?string $fileName = null, ?string $friendName = null): Media
    {
        $this->fileValidator->validate($file);

        $fileAdder = $model->addMedia($file->getRealPath());

        if ($fileName) {
            $fileAdder->usingFileName($fileName);
        } else {
            $fileAdder->usingFileName($file->getClientOriginalName());
        }

        if ($friendName) {
            $fileAdder->usingName($friendName);
        } else {
            $fileAdder->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        }

        return $fileAdder->toMediaCollection($collection);
    }

    public function attachFromRequest(Model $model, string $key, string $collection, ?string $fileName = null, ?string $friendName = null): Media
    {
        $file = request()->file($key);

        if (! $file) {
            throw new \InvalidArgumentException("No file found for key: {$key}");
        }

        if (is_array($file)) {
            throw new \InvalidArgumentException("Multiple files found for key: {$key}. Use attachMultipleFromRequest or handle individually.");
        }

        return $this->attach($model, $file, $collection, $fileName, $friendName);
    }

    public function remove(Media $media): void
    {
        $media->delete();
    }
}
