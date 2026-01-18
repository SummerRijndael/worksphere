<?php

namespace App\Http\Controllers;

use App\Models\Chat\ChatMessage;
use App\Services\Chat\ChatMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatMediaController extends Controller
{
    /**
     * View/stream a chat media file.
     */
    public function view(int $mediaId): StreamedResponse|Response|JsonResponse
    {
        $media = $this->getAuthorizedMedia($mediaId);
        if (! $media instanceof Media) {
            return $media;
        }

        $path = $media->getPathRelativeToRoot();

        Log::info('ChatMediaController@view: Serving media', [
            'media_id' => $media->id,
            'disk' => $media->disk,
            'path' => $path,
            'mime_type' => $media->mime_type,
            'user_id' => Auth::id(),
        ]);

        return response()->stream(
            function () use ($media, $path) {
                try {
                    $stream = Storage::disk($media->disk)->readStream($path);
                    if (is_resource($stream)) {
                        fpassthru($stream);
                        fclose($stream);
                    } else {
                        Log::warning('ChatMediaController@view: Stream is not a resource', ['media_id' => $media->id]);
                    }
                } catch (\Exception $e) {
                    Log::error('ChatMediaController@view: Failed to read stream', [
                        'media_id' => $media->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            },
            200,
            [
                'Content-Type' => $media->mime_type,
                'Content-Length' => $media->size,
                'Cache-Control' => 'private, max-age=3600',
            ]
        );
    }

    /**
     * Download a chat media file.
     */
    public function download(int $mediaId): BinaryFileResponse|StreamedResponse|Response|JsonResponse
    {
        $media = $this->getAuthorizedMedia($mediaId);
        if (! $media instanceof Media) {
            return $media;
        }

        $originalFilename = $media->getCustomProperty('original_filename') ?? $media->file_name;
        $path = $media->getPathRelativeToRoot();

        Log::info('ChatMediaController@download: Downloading media', [
            'media_id' => $media->id,
            'disk' => $media->disk,
            'path' => $path,
            'filename' => $originalFilename,
            'user_id' => Auth::id(),
        ]);

        return Storage::disk($media->disk)->download(
            $path,
            $originalFilename,
            [
                'Content-Type' => $media->mime_type,
            ]
        );
    }

    /**
     * Get a specific conversion of a media file (thumb, web).
     */
    public function conversion(int $mediaId, string $conversion): StreamedResponse|Response|JsonResponse
    {
        $media = $this->getAuthorizedMedia($mediaId);
        if (! $media instanceof Media) {
            return $media;
        }

        // Validate conversion name
        $allowedConversions = ['thumb', 'web', 'optimized', 'webp'];
        if (! in_array($conversion, $allowedConversions, true)) {
            Log::warning('ChatMediaController@conversion: Invalid conversion requested', [
                'media_id' => $mediaId,
                'conversion' => $conversion,
                'user_id' => Auth::id(),
            ]);

            return response()->json(['error' => 'Invalid conversion'], 400);
        }

        $path = $media->getPathRelativeToRoot($conversion);
        $disk = $media->disk;

        Log::info('ChatMediaController@conversion: Serving conversion', [
            'media_id' => $media->id,
            'conversion' => $conversion,
            'disk' => $disk,
            'path' => $path,
            'user_id' => Auth::id(),
        ]);

        // Check if conversion exists
        if (! $media->hasGeneratedConversion($conversion) || ! Storage::disk($disk)->exists($path)) {
            Log::info('ChatMediaController@conversion: Conversion not found, falling back to original', [
                'media_id' => $media->id,
                'conversion' => $conversion,
            ]);

            // Fall back to original if conversion doesn't exist
            $originalPath = $media->getPathRelativeToRoot();

            return response()->stream(
                function () use ($media, $originalPath) {
                    try {
                        $stream = Storage::disk($media->disk)->readStream($originalPath);
                        if (is_resource($stream)) {
                            fpassthru($stream);
                            fclose($stream);
                        } else {
                            // Fallback or empty response if file missing
                            echo '';
                        }
                    } catch (\Exception $e) {
                        Log::error('ChatMediaController@conversion: Failed to read stream', [
                            'media_id' => $media->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                },
                200,
                [
                    'Content-Type' => $media->mime_type,
                    'Content-Length' => $media->size,
                    'Cache-Control' => 'private, max-age=3600',
                ]
            );
        }

        return response()->stream(
            function () use ($disk, $path, $media) {
                try {
                    $stream = Storage::disk($disk)->readStream($path);
                    if (is_resource($stream)) {
                        fpassthru($stream);
                        fclose($stream);
                    } else {
                        echo '';
                    }
                } catch (\Exception $e) {
                    Log::error('ChatMediaController@conversion: Failed to read conversion stream', [
                        'media_id' => $media->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            },
            200,
            [
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'private, max-age=86400',
            ]
        );
    }

    /**
     * Get an authorized media item or return an error response.
     */
    protected function getAuthorizedMedia(int $mediaId): Media|Response|JsonResponse
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $media = Media::find($mediaId);

        if (! $media) {
            return response()->json(['error' => 'Media not found'], 404);
        }

        // Verify the media belongs to a chat message
        if ($media->model_type !== ChatMessage::class) {
            return response()->json(['error' => 'Invalid media type'], 400);
        }

        // Check if user can access this media
        if (! app(ChatMediaService::class)->canAccessMedia($mediaId, $user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return $media;
    }
}
