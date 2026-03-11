<?php

namespace App\Support;

use App\Models\Meeting;
use Illuminate\Http\Request;

class MeetingParticipantSession
{
    private const COOKIE_NAME = 'wsm_participants';

    public static function cookieName(): string
    {
        return self::COOKIE_NAME;
    }

    private static function meetingKey(Meeting $meeting): string
    {
        return strtolower($meeting->public_id);
    }

    private static function decodeParticipantMap(Request $request): array
    {
        $raw = $request->cookie(self::cookieName());
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($value, $key) => is_string($key) && is_string($value) && trim($value) !== '')
            ->mapWithKeys(fn ($value, $key) => [strtolower((string) $key) => trim((string) $value)])
            ->all();
    }

    public static function extractSessionParticipantId(): ?string
    {
        $participantId = session('meeting_participant_id') ?: session('participant_id');
        if (! is_string($participantId)) {
            return null;
        }

        $participantId = trim($participantId);

        return $participantId !== '' ? $participantId : null;
    }

    public static function extractCookieParticipantId(Request $request, Meeting $meeting): ?string
    {
        $participantMap = self::decodeParticipantMap($request);
        $meetingKey = self::meetingKey($meeting);

        if (isset($participantMap[$meetingKey]) && is_string($participantMap[$meetingKey])) {
            $participantId = trim($participantMap[$meetingKey]);
            if ($participantId !== '') {
                return $participantId;
            }
        }

        // Legacy fallback for previous per-meeting cookie naming.
        $legacyName = 'wsm_pid_'.$meetingKey;
        $legacyParticipantId = $request->cookie($legacyName);
        if (! is_string($legacyParticipantId)) {
            return null;
        }

        $legacyParticipantId = trim($legacyParticipantId);

        return $legacyParticipantId !== '' ? $legacyParticipantId : null;
    }

    public static function buildCookieValue(Request $request, Meeting $meeting, string $participantPublicId): string
    {
        $participantMap = self::decodeParticipantMap($request);
        $participantMap[self::meetingKey($meeting)] = $participantPublicId;

        // Keep cookie bounded in size by retaining the latest 25 meeting mappings.
        if (count($participantMap) > 25) {
            $participantMap = array_slice($participantMap, -25, null, true);
        }

        return json_encode($participantMap, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Resolve guest participant identity from secure server-side sources.
     * Priority: HttpOnly cookie -> session.
     * Optional X-Participant-ID header is accepted only when it matches.
     */
    public static function resolveGuestParticipantId(Request $request, Meeting $meeting): ?string
    {
        $cookieParticipantId = self::extractCookieParticipantId($request, $meeting);
        $sessionParticipantId = self::extractSessionParticipantId();

        $effectiveParticipantId = $cookieParticipantId ?: $sessionParticipantId;
        if (! $effectiveParticipantId) {
            return null;
        }

        // Keep session in sync with cookie to support legacy session checks.
        if ($cookieParticipantId && strcasecmp((string) $sessionParticipantId, $cookieParticipantId) !== 0) {
            session(['meeting_participant_id' => $cookieParticipantId]);
            $effectiveParticipantId = $cookieParticipantId;
        }

        $headerParticipantId = $request->header('X-Participant-ID');
        if ($headerParticipantId && strcasecmp($headerParticipantId, $effectiveParticipantId) !== 0) {
            return null;
        }

        return $effectiveParticipantId;
    }
}
