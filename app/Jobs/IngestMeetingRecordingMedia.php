<?php

namespace App\Jobs;

use App\Models\MeetingRecording;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IngestMeetingRecordingMedia implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $recordingId
    ) {
        $this->onQueue('media-conversions');
    }

    public function uniqueId(): string
    {
        return $this->recordingId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $recording = MeetingRecording::with('meeting')->find($this->recordingId);
        if (! $recording) {
            return;
        }

        if ($recording->status !== 'completed' || ! $recording->download_url) {
            return;
        }

        if ($recording->recording_media) {
            return;
        }

        $extension = pathinfo((string) parse_url($recording->download_url, PHP_URL_PATH), PATHINFO_EXTENSION);
        $extension = $extension ? strtolower($extension) : 'mp4';
        $obscuredFilename = Str::uuid().'.'.$extension;
        $disk = config('services.cloudflare_realtime.recording_storage_disk', 'private');

        $media = $recording->addMediaFromUrl($recording->download_url)
            ->usingName($recording->display_name)
            ->usingFileName($obscuredFilename)
            ->withCustomProperties([
                'source' => 'cloudflare-realtime',
                'cf_recording_id' => $recording->cf_recording_id,
                'started_at' => $recording->created_at?->toIso8601String(),
                'duration_seconds' => $recording->duration_seconds,
                'original_url' => $recording->download_url,
            ])
            ->toMediaCollection(MeetingRecording::MEDIA_COLLECTION, $disk);

        $metadata = $recording->cf_metadata ?? [];
        $metadata['ingestion'] = [
            'media_id' => $media->id,
            'ingested_at' => now()->toIso8601String(),
        ];

        $recording->forceFill(['cf_metadata' => $metadata])->save();
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('cloudflare_realtime')->error('Meeting recording ingestion failed', [
            'recording_id' => $this->recordingId,
            'error' => $exception->getMessage(),
        ]);
    }
}
