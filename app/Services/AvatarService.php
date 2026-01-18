<?php

namespace App\Services;

use App\Contracts\AvatarContract;
use App\Contracts\AvatarData;
use App\Models\Chat\Chat;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

/**
 * Unified avatar service for resolving and managing avatars.
 *
 * This is the single source of truth for all avatar operations
 * across User, Team, and Chat entities.
 */
class AvatarService implements AvatarContract
{
    /**
     * Resolve avatar data for any supported entity.
     */
    public function resolve(mixed $entity, string $variant = 'optimized'): AvatarData
    {
        // Handle null entity
        if ($entity === null) {
            return new AvatarData(
                url: null,
                fallback: $this->getFallbackUrl(),
                initials: '?',
                color: $this->getColorFromId('default'),
            );
        }

        // Handle array (participant data from API)
        if (is_array($entity)) {
            return $this->resolveFromArray($entity);
        }

        // Handle Eloquent models
        if ($entity instanceof User) {
            return $this->resolveUser($entity, $variant);
        }

        if ($entity instanceof Team) {
            return $this->resolveTeam($entity, $variant);
        }

        if ($entity instanceof Chat) {
            return $this->resolveChat($entity, $variant);
        }

        // Unknown entity type
        Log::warning('AvatarService: Unknown entity type', ['type' => get_class($entity)]);

        return new AvatarData(
            url: null,
            fallback: $this->getFallbackUrl(),
            initials: '?',
            color: $this->getColorFromId('default'),
        );
    }

    /**
     * Resolve avatar for a User.
     */
    protected function resolveUser(User $user, string $variant): AvatarData
    {
        $url = null;

        // Priority 1: Media Library
        if ($user->hasMedia('avatars')) {
            $url = $user->getFirstMediaUrl('avatars', $variant);
        }

        return new AvatarData(
            url: $url,
            fallback: $this->getFallbackUrl(),
            initials: $this->getInitials($user->name ?? 'User'),
            color: $this->getColorFromId($user->public_id ?? $user->id),
        );
    }

    /**
     * Resolve avatar for a Team.
     */
    protected function resolveTeam(Team $team, string $variant): AvatarData
    {
        $url = null;

        // Priority 1: Media Library
        if ($team->hasMedia('avatars')) {
            $url = $team->getFirstMediaUrl('avatars', $variant);
        }

        return new AvatarData(
            url: $url,
            fallback: $this->getFallbackUrl(),
            initials: $this->getInitials($team->name ?? 'Team'),
            color: $this->getColorFromId($team->public_id ?? $team->id),
        );
    }

    /**
     * Resolve avatar for a Chat.
     */
    protected function resolveChat(Chat $chat, string $variant): AvatarData
    {
        // DM chats: use other participant's avatar
        if ($chat->type === Chat::TYPE_DM) {
            return $this->resolveDmChat($chat, $variant);
        }

        // Group/Team chats: check for uploaded avatar
        return $this->resolveGroupChat($chat, $variant);
    }

    /**
     * Resolve avatar for a DM chat (other participant's avatar).
     */
    protected function resolveDmChat(Chat $chat, string $variant): AvatarData
    {
        $currentUserId = auth()->id();
        $participants = $chat->participants;
        $otherParticipant = $participants->firstWhere('id', '!=', $currentUserId);

        if ($otherParticipant) {
            return $this->resolveUser($otherParticipant, $variant);
        }

        // Fallback if no other participant found
        return new AvatarData(
            url: null,
            fallback: $this->getFallbackUrl(),
            initials: 'DM',
            color: $this->getColorFromId($chat->public_id ?? (string) $chat->id),
        );
    }

    /**
     * Resolve avatar for a group chat.
     *
     * Priority:
     * 1. Uploaded group avatar
     * 2. Composite avatar for multiple participants
     * 3. Static placeholder
     */
    protected function resolveGroupChat(Chat $chat, string $variant): AvatarData
    {
        // Priority 1: Check for uploaded avatar in Media Library (Spatie)
        if ($chat->hasMedia('avatars')) {
            $url = $chat->getFirstMediaUrl('avatars', $variant);

            return new AvatarData(
                url: $url,
                fallback: $this->getGroupFallbackUrl($chat->participants->count()),
                initials: $this->getInitials($chat->name ?? 'Group'),
                color: $this->getColorFromId($chat->public_id ?? (string) $chat->id),
            );
        }

        // Priority 2: Check for legacy/manual avatar path in storage
        if ($chat->avatar) {
            $url = $this->resolveStoragePath($chat->avatar);
            if ($url) {
                $participants = $chat->participants;

                return new AvatarData(
                    url: $url,
                    fallback: $this->getGroupFallbackUrl($participants->count()),
                    initials: $this->getInitials($chat->name ?? 'Group'),
                    color: $this->getColorFromId($chat->public_id ?? (string) $chat->id),
                );
            }
        }

        $participants = $chat->participants;
        $participantCount = $participants->count();

        // Single member: use static placeholder
        if ($participantCount <= 1) {
            return new AvatarData(
                url: null,
                fallback: config('avatar.group_chat.fallback_single', $this->getFallbackUrl()),
                initials: $this->getInitials($chat->name ?? 'Group'),
                color: $this->getColorFromId($chat->public_id ?? (string) $chat->id),
            );
        }

        // Multiple members: return composite data
        // Frontend will render the composite (u1)(u2)(u3)(4+)
        return new AvatarData(
            url: null,
            fallback: config('avatar.group_chat.fallback_group', $this->getFallbackUrl()),
            initials: $this->getInitials($chat->name ?? 'Group'),
            color: $this->getColorFromId($chat->public_id ?? (string) $chat->id),
        );
    }

    /**
     * Resolve avatar from array data (API participant format).
     */
    protected function resolveFromArray(array $data): AvatarData
    {
        $url = $data['avatar_url'] ?? $data['avatar'] ?? null;
        $name = $data['name'] ?? 'User';
        $identifier = $data['public_id'] ?? $data['id'] ?? Str::random(8);

        return new AvatarData(
            url: $url,
            fallback: $this->getFallbackUrl(),
            initials: $this->getInitials($name),
            color: $this->getColorFromId($identifier),
        );
    }

    /**
     * Resolve a storage path to a URL.
     */
    protected function resolveStoragePath(string $path): ?string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $disk = config('avatar.disk', 'public');

        try {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->url($path);
            }
        } catch (\Throwable $e) {
            Log::warning('AvatarService: Failed to resolve storage path', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Process and store an uploaded avatar file.
     */
    public function processUpload(UploadedFile $file, HasMedia $entity, string $collection = 'avatars'): void
    {
        // Clear existing avatars in this collection
        $entity->clearMediaCollection($collection);

        // Add new media with processing
        $entity->addMedia($file)
            ->usingFileName((string) Str::uuid().'.'.config('avatar.format', 'webp'))
            ->toMediaCollection($collection);

        // Note: EXIF stripping and WebP conversion are handled by Spatie Media Library
        // via the model's registerMediaConversions() method
    }

    /**
     * Sync avatar from a social provider URL.
     */
    public function syncFromSocial(string $url, HasMedia $entity): bool
    {
        if (empty($url)) {
            return false;
        }

        $compareFilename = config('avatar.social_sync.compare_filename', true);

        // Check if filename comparison is enabled
        if ($compareFilename && $entity->hasMedia('avatars')) {
            $existingMedia = $entity->getFirstMedia('avatars');

            if ($existingMedia) {
                // Extract filename from URL and compare
                $newFilename = $this->extractFilenameFromUrl($url);
                $existingCustom = $existingMedia->getCustomProperty('source_filename');

                if ($existingCustom && $existingCustom === $newFilename) {
                    Log::debug('AvatarService: Skipping social sync, filename unchanged', [
                        'filename' => $newFilename,
                    ]);

                    return false;
                }
            }
        }

        try {
            // Clear existing and download new
            $entity->clearMediaCollection('avatars');

            $media = $entity->addMediaFromUrl($url)
                ->usingFileName((string) Str::uuid().'.'.config('avatar.format', 'webp'))
                ->withCustomProperties([
                    'source' => 'social',
                    'source_filename' => $this->extractFilenameFromUrl($url),
                    'source_url' => $url,
                ])
                ->toMediaCollection('avatars');

            Log::info('AvatarService: Social avatar synced', [
                'entity_type' => get_class($entity),
                'media_id' => $media->id,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('AvatarService: Failed to sync social avatar', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Extract filename from a URL.
     */
    protected function extractFilenameFromUrl(string $url): string
    {
        $parsed = parse_url($url, PHP_URL_PATH);

        return $parsed ? basename($parsed) : md5($url);
    }

    /**
     * Generate initials from a name.
     */
    public function getInitials(string $name): string
    {
        $words = explode(' ', trim($name));
        $initials = '';

        foreach (array_slice($words, 0, 2) as $word) {
            if (strlen($word) > 0) {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            }
        }

        return $initials ?: '?';
    }

    /**
     * Generate a consistent color from an identifier.
     */
    public function getColorFromId(string|int $identifier): string
    {
        $colors = config('avatar.colors', [
            '#6366f1', '#8b5cf6', '#a855f7', '#ec4899',
            '#f43f5e', '#ef4444', '#f97316', '#eab308',
            '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6',
        ]);

        // Generate consistent index from identifier
        $hash = crc32((string) $identifier);
        $index = abs($hash) % count($colors);

        return $colors[$index];
    }

    /**
     * Get the default fallback avatar URL.
     */
    public function getFallbackUrl(): string
    {
        return asset(config('avatar.fallback', '/static/images/avatar/blank.png'));
    }

    /**
     * Get fallback URL for group chats based on participant count.
     */
    protected function getGroupFallbackUrl(int $participantCount): string
    {
        if ($participantCount <= 1) {
            return asset(config('avatar.group_chat.fallback_single', '/static/images/avatar/group-single.png'));
        }

        return asset(config('avatar.group_chat.fallback_group', '/static/images/avatar/group.png'));
    }
}
