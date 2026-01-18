<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;

/**
 * Contract for avatar resolution and management.
 *
 * Provides a unified interface for handling avatars across
 * User, Team, and Chat entities.
 */
interface AvatarContract
{
    /**
     * Resolve avatar data for any supported entity.
     *
     * Supported entities: User, Team, Chat, or associative array with avatar data.
     *
     * @param  mixed  $entity  The entity to resolve avatar for
     * @param  string  $variant  The size variant ('thumb' or 'optimized')
     */
    public function resolve(mixed $entity, string $variant = 'optimized'): AvatarData;

    /**
     * Process and store an uploaded avatar file.
     *
     * - Strips EXIF data
     * - Converts to WebP format
     * - Generates size variants (thumb, optimized)
     *
     * @param  UploadedFile  $file  The uploaded file
     * @param  HasMedia  $entity  The entity to attach avatar to
     * @param  string  $collection  Media collection name (default: 'avatars')
     */
    public function processUpload(UploadedFile $file, HasMedia $entity, string $collection = 'avatars'): void;

    /**
     * Sync avatar from a social provider URL.
     *
     * Compares filename if configured, skips download if unchanged.
     *
     * @param  string  $url  The social provider avatar URL
     * @param  HasMedia  $entity  The entity to sync avatar for
     * @return bool True if avatar was updated, false if skipped
     */
    public function syncFromSocial(string $url, HasMedia $entity): bool;

    /**
     * Generate initials from a name.
     *
     * @param  string  $name  Full name
     * @return string Up to 2 character initials
     */
    public function getInitials(string $name): string;

    /**
     * Generate a consistent color from an identifier.
     *
     * @param  string|int  $identifier  Unique identifier (e.g., public_id, id)
     * @return string Hex color code
     */
    public function getColorFromId(string|int $identifier): string;

    /**
     * Get the fallback avatar URL.
     */
    public function getFallbackUrl(): string;
}
