<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare RealtimeKit v2 API wrapper.
 *
 * Base URL: https://api.realtime.cloudflare.com/v2/
 * Auth:     HTTP Basic — base64(organizationId:apiKey)
 *
 * Cloudflare Dashboard for credentials:
 *   → dash.realtime.cloudflare.com → API Keys
 *
 * Required .env keys:
 *   CLOUDFLARE_REALTIME_ORG_ID       — Organization ID (from RealtimeKit dashboard)
 *   CLOUDFLARE_REALTIME_API_KEY      — API Key        (from RealtimeKit dashboard)
 *   CLOUDFLARE_REALTIME_APP_ID       — App ID         (from RealtimeKit dashboard after creating an app)
 *   MEETING_RECORDING_ENABLED=true   — Dev feature toggle
 */
class CloudflareRealtimeKitService
{
    private ?string $baseUrl = 'https://api.cloudflare.com/client/v4';
    private ?string $accountId;
    private ?string $apiToken;
    private ?string $appId;

    public function __construct()
    {
        $this->accountId = config('services.cloudflare_realtime.account_id') ?? '';
        $this->apiToken  = config('services.cloudflare_realtime.api_token') ?? '';
        $this->appId     = config('services.cloudflare_realtime.app_id')     ?? '';
    }

    // ─── Meeting Management ───────────────────────────────────────────────────

    /**
     * Create a new RealtimeKit meeting for PRO recording sessions.
     * Returns ['meeting_id' => '...', ...].
     */
    public function createMeeting(array $options = []): array
    {
        $response = $this->client()->post("{$this->baseUrl}/accounts/{$this->accountId}/realtime/kit/{$this->appId}/meetings", $options);

        $this->assertSuccess($response, 'createMeeting');

        return $response->json();
    }

    /**
     * Add a participant to a RealtimeKit meeting.
     * Returns participant data including the auth_token needed by the frontend SDK.
     *
     * @param  string  $cfMeetingId  The Cloudflare RealtimeKit meeting ID
     * @param  array   $participantData  ['name', 'preset_name', 'custom_participant_id']
     */
    public function addParticipant(string $cfMeetingId, array $participantData): array
    {
        $response = $this->client()->post(
            "{$this->baseUrl}/accounts/{$this->accountId}/realtime/kit/{$this->appId}/meetings/{$cfMeetingId}/participants",
            $participantData
        );

        $this->assertSuccess($response, 'addParticipant');

        return $response->json();
    }

    /**
     * Refresh an existing participant's auth token (tokens are time-bound).
     */
    public function refreshParticipantToken(string $cfMeetingId, string $participantId): array
    {
        $response = $this->client()->post(
            "{$this->baseUrl}/accounts/{$this->accountId}/realtime/kit/{$this->appId}/meetings/{$cfMeetingId}/participants/{$participantId}/token",
        );

        $this->assertSuccess($response, 'refreshParticipantToken');

        return $response->json();
    }

    // ─── Recording Management ─────────────────────────────────────────────────

    /**
     * Start a recording for a RealtimeKit meeting.
     *
     * @param  string  $cfMeetingId
     * @param  array   $options      ['max_seconds' => int, 'watermark' => bool]
     */
    public function startRecording(string $cfMeetingId, array $options = []): array
    {
        $maxSeconds = $options['max_seconds'] ?? 86400;
        $params = [
            'meeting_id'   => $cfMeetingId,
            'max_seconds'  => $maxSeconds,
            'audio_config' => [
                'codec'   => 'AAC',
                'channel' => 'stereo',
            ],
        ];

        // Add Watermark if enabled/configured
        $watermarkUrl = config('services.cloudflare_realtime.watermark_url');
        if ($watermarkUrl) {
            $params['video_config'] = [
                'watermark' => [
                    'url'      => $watermarkUrl,
                    'position' => config('services.cloudflare_realtime.watermark_position', 'right bottom'),
                    'size'     => [
                        'height' => (int) config('services.cloudflare_realtime.watermark_height', 40),
                        'width'  => (int) config('services.cloudflare_realtime.watermark_width', 160),
                    ],
                ]
            ];
        }

        $response = $this->client()->post(
            "{$this->baseUrl}/accounts/{$this->accountId}/realtime/kit/{$this->appId}/recordings",
            $params
        );

        $this->assertSuccess($response, 'startRecording');

        return $response->json();
    }

    /**
     * Stop an active recording.
     *
     * @param  string  $cfRecordingId  The recording ID returned by startRecording()
     */
    public function stopRecording(string $cfRecordingId): array
    {
        $response = $this->client()->put(
            "{$this->baseUrl}/accounts/{$this->accountId}/realtime/kit/{$this->appId}/recordings/{$cfRecordingId}",
            ['action' => 'stop']
        );

        $this->assertSuccess($response, 'stopRecording');

        return $response->json();
    }

    /**
     * Get details of a specific recording (status, download_url, duration, etc.).
     */
    public function getRecording(string $cfRecordingId): array
    {
        $response = $this->client()->get(
            "{$this->baseUrl}/accounts/{$this->accountId}/realtime/kit/{$this->appId}/recordings/{$cfRecordingId}"
        );

        $this->assertSuccess($response, 'getRecording');

        return $response->json();
    }

    /**
     * Get the active recording for a meeting (if any).
     */
    public function getActiveRecording(string $cfMeetingId): ?array
    {
        $response = $this->client()->get(
            "{$this->baseUrl}/accounts/{$this->accountId}/realtime/kit/{$this->appId}/recordings/active-recording/{$cfMeetingId}"
        );

        if ($response->status() === 404) {
            return null;
        }

        $this->assertSuccess($response, 'getActiveRecording');

        return $response->json();
    }

    // ─── Internal Helpers ─────────────────────────────────────────────────────

    /**
     * @return \Illuminate\Http\Client\PendingRequest
     */
    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->apiToken)
            ->withHeaders([
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])->timeout(15);
    }

    private function assertSuccess(\Illuminate\Http\Client\Response $response, string $context): void
    {
        if ($response->failed()) {
            Log::channel('cloudflare_realtime')->error("CloudflareRealtimeKitService::{$context} failed", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new \RuntimeException(
                "Cloudflare RealtimeKit API error [{$response->status()}] in {$context}: " . $response->body()
            );
        }
    }
}
