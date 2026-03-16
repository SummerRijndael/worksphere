<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ChatMediaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $size = (int) ($this->size ?? 0);
        $sizeLabel = $size >= 1048576
            ? number_format($size / 1048576, 1) . ' MB'
            : number_format(max($size, 1) / 1024, 1) . ' KB';

        $isVoiceClip = (bool) $this->getCustomProperty('is_voice_clip', false);
        $mediaKind = (string) ($this->getCustomProperty('media_kind') ?: $this->inferMediaKind((string) $this->mime_type, $isVoiceClip));
        
        $isImage = $mediaKind === 'image';
        $isVideo = $mediaKind === 'video';

        $thumbConversion = null;
        if ($isImage && $this->hasGeneratedConversion('thumb')) {
            $thumbConversion = 'thumb';
        } elseif ($isVideo && $this->hasGeneratedConversion('video_thumb')) {
            $thumbConversion = 'video_thumb';
        }

        $viewUrl = route('chat.media.view', ['mediaId' => $this->id], false);
        $downloadUrl = route('chat.media.download', ['mediaId' => $this->id], false);

        return [
            'id' => $this->id,
            'name' => Str::limit($this->getCustomProperty('original_filename') ?? $this->file_name, 40),
            'size' => $size,
            'size_human' => $sizeLabel,
            'mime_type' => $this->mime_type,
            'is_image' => $isImage,
            'is_audio' => $mediaKind === 'audio',
            'is_video' => $isVideo,
            'is_voice_clip' => $isVoiceClip,
            'media_kind' => $mediaKind,
            'duration_seconds' => $this->normalizeDurationSeconds($this->getCustomProperty('duration_seconds')),
            'created_at_human' => $this->created_at?->shortRelativeDiffForHumans() ?? '',
            'url' => $viewUrl,
            'download_url' => $downloadUrl,
            'thumb_url' => $thumbConversion
                ? route('chat.media.conversion', ['mediaId' => $this->id, 'conversion' => $thumbConversion], false)
                : null,
        ];
    }

    protected function inferMediaKind(string $mimeType, bool $isVoiceClip = false): string
    {
        if ($isVoiceClip) {
            return 'audio';
        }

        $normalized = strtolower(trim($mimeType));
        if ($normalized === '') {
            return 'file';
        }

        if (str_starts_with($normalized, 'image/')) {
            return 'image';
        }

        if (str_starts_with($normalized, 'audio/')) {
            return 'audio';
        }

        if (str_starts_with($normalized, 'video/')) {
            return 'video';
        }

        return 'file';
    }

    protected function normalizeDurationSeconds(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $seconds = (int) round((float) $value);

        return $seconds >= 0 ? $seconds : null;
    }
}
