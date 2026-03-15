<?php

namespace WorkSphere\Chat\Contracts;

interface ChatParticipant
{
    /**
     * Get the display name of the participant.
     */
    public function getChatDisplayName(): string;

    /**
     * Get the avatar URL of the participant.
     */
    public function getChatAvatarUrl(): ?string;
}
