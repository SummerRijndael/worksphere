<?php

namespace App\Services\Support\Ai\Agents;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\SerializableClosure\SerializableClosure;

class ProviderOptionsStructuredAnonymousAgent extends ProviderOptionsAnonymousAgent implements HasStructuredOutput
{
    public SerializableClosure $schemaClosure;

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        string $instructions,
        iterable $messages,
        iterable $tools,
        Closure $schema,
        array $providerOptions = [],
    ) {
        parent::__construct(
            instructions: $instructions,
            messages: $messages,
            tools: $tools,
            providerOptions: $providerOptions,
        );

        $this->schemaClosure = new SerializableClosure($schema);
    }

    public function schema(JsonSchema $schema): array
    {
        return call_user_func($this->schemaClosure, $schema);
    }
}
