<?php

namespace App\Contracts;

/**
 * Data Transfer Object for avatar resolution results.
 */
final class AvatarData
{
    public function __construct(
        public readonly ?string $url,
        public readonly string $fallback,
        public readonly string $initials,
        public readonly string $color,
    ) {}

    /**
     * Get the best available avatar URL (url or fallback).
     */
    public function getUrl(): string
    {
        return $this->url ?? $this->fallback;
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array{url: string|null, fallback: string, initials: string, color: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'fallback' => $this->fallback,
            'initials' => $this->initials,
            'color' => $this->color,
        ];
    }
}
