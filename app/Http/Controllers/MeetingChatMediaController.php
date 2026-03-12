<?php

namespace App\Http\Controllers;

use App\Models\MeetingMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeetingChatMediaController extends Controller
{
    public function view(Request $request, int $mediaId): StreamedResponse|Response|JsonResponse
    {
        $media = $this->resolveMeetingMedia($mediaId);
        if (! $media instanceof Media) {
            return $media;
        }

        return $this->streamMedia($request, $media, $media->getPathRelativeToRoot(), $media->mime_type, 'private, max-age=3600');
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

    public function conversion(Request $request, int $mediaId, string $conversion): StreamedResponse|Response|JsonResponse
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
                $request,
                $media,
                $media->getPathRelativeToRoot(),
                $media->mime_type,
                'private, max-age=3600'
            );
        }

        return $this->streamMedia($request, $media, $path, 'image/webp', 'private, max-age=86400');
    }

    protected function streamMedia(Request $request, Media $media, string $path, string $contentType, string $cacheControl): StreamedResponse|Response
    {
        $disk = Storage::disk($media->disk);

        if (! $disk->exists($path)) {
            return response()->noContent(404);
        }

        $size = (int) ($disk->size($path) ?: $media->size ?: 0);
        if ($size <= 0) {
            return response()->noContent(404);
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;

        $rangeHeader = $request->header('Range');
        if (is_string($rangeHeader) && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $matches)) {
            $rangeStart = $matches[1] !== '' ? (int) $matches[1] : null;
            $rangeEnd = $matches[2] !== '' ? (int) $matches[2] : null;

            if ($rangeStart === null && $rangeEnd !== null) {
                $rangeStart = max($size - $rangeEnd, 0);
                $rangeEnd = $size - 1;
            }

            if ($rangeStart !== null) {
                $start = max($rangeStart, 0);
            }

            if ($rangeEnd !== null) {
                $end = min($rangeEnd, $size - 1);
            }

            if ($start > $end || $start >= $size) {
                return response('', 416, [
                    'Content-Range' => "bytes */{$size}",
                    'Accept-Ranges' => 'bytes',
                ]);
            }

            $status = 206;
        }

        $length = $end - $start + 1;
        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => (string) $length,
            'Cache-Control' => $cacheControl,
            'Accept-Ranges' => 'bytes',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($disk, $path, $start, $length) {
            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                return;
            }

            $meta = stream_get_meta_data($stream);
            $seekable = (bool) ($meta['seekable'] ?? false);
            if ($start > 0) {
                if ($seekable) {
                    fseek($stream, $start);
                } else {
                    $skip = $start;
                    while ($skip > 0 && ! feof($stream)) {
                        $chunk = fread($stream, (int) min(8192, $skip));
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $skip -= strlen($chunk);
                    }
                }
            }

            $remaining = $length;
            while ($remaining > 0 && ! feof($stream)) {
                $read = (int) min(8192, $remaining);
                $buffer = fread($stream, $read);
                if ($buffer === false || $buffer === '') {
                    break;
                }
                echo $buffer;
                $remaining -= strlen($buffer);
            }

            fclose($stream);
        }, $status, $headers);
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
