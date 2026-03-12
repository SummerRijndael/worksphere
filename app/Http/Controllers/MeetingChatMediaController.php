<?php

namespace App\Http\Controllers;

use App\Models\MeetingMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeetingChatMediaController extends Controller
{
    public function view(int $mediaId): StreamedResponse|Response|JsonResponse
    {
        $media = $this->resolveMeetingMedia($mediaId);
        if (! $media instanceof Media) {
            return $media;
        }

        return $this->streamMedia($media, $media->getPathRelativeToRoot(), $media->mime_type, 'private, max-age=3600');
    }

    public function download(int $mediaId): StreamedResponse|Response|JsonResponse
    {
        $media = $this->resolveMeetingMedia($mediaId);
        if (! $media instanceof Media) {
            return $media;
        }

        $filename = $media->getCustomProperty('original_filename') ?? $media->file_name;
        $path = $media->getPathRelativeToRoot();

        return Storage::disk($media->disk)->download(
            $path,
            (string) $filename,
            ['Content-Type' => (string) $media->mime_type]
        );
    }

    public function conversion(int $mediaId, string $conversion): StreamedResponse|Response|JsonResponse
    {
        $media = $this->resolveMeetingMedia($mediaId);
        if (! $media instanceof Media) {
            return $media;
        }

        $allowedConversions = ['thumb', 'web'];
        if (! in_array($conversion, $allowedConversions, true)) {
            return response()->json(['error' => 'Invalid conversion'], 400);
        }

        $path = $media->getPathRelativeToRoot($conversion);
        if (! $media->hasGeneratedConversion($conversion) || ! Storage::disk($media->disk)->exists($path)) {
            return $this->streamMedia(
                $media,
                $media->getPathRelativeToRoot(),
                $media->mime_type,
                'private, max-age=3600'
            );
        }

        return $this->streamMedia($media, $path, 'image/webp', 'private, max-age=86400');
    }

    protected function streamMedia(Media $media, string $path, string $contentType, string $cacheControl): StreamedResponse|Response
    {
        if (! Storage::disk($media->disk)->exists($path)) {
            return response()->noContent(404);
        }

        return response()->stream(
            function () use ($media, $path) {
                $stream = Storage::disk($media->disk)->readStream($path);
                if (! is_resource($stream)) {
                    return;
                }

                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $contentType,
                'Cache-Control' => $cacheControl,
            ]
        );
    }

    protected function resolveMeetingMedia(int $mediaId): Media|JsonResponse
    {
        $media = Media::find($mediaId);
        if (! $media) {
            return response()->json(['error' => 'Media not found'], 404);
        }

        if ($media->model_type !== MeetingMessage::class) {
            return response()->json(['error' => 'Invalid media type'], 400);
        }

        if ($media->collection_name !== MeetingMessage::MEDIA_COLLECTION) {
            return response()->json(['error' => 'Invalid media collection'], 400);
        }

        return $media;
    }
}

