<?php

namespace App\Services\Support\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class ProviderOptionsAnonymousAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        public string $instructions,
        public iterable $messages,
        public iterable $tools,
        protected array $providerOptions = [],
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function messages(): iterable
    {
        return $this->messages;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions;
    }
}
