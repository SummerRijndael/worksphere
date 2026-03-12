<?php

namespace App\Services\Chat\Adapters;

use App\Contracts\ChatChannelAdapterContract;
use InvalidArgumentException;

class AdapterFactory
{
    /**
     * @var array<string, class-string<ChatChannelAdapterContract>>
     */
    protected static array $adapters = [
        'chat' => ExistingChatAdapter::class,
        'meeting' => MeetingChatAdapter::class,
        'ai_support' => AiSupportChatAdapter::class,
    ];

    public function make(string $key): ChatChannelAdapterContract
    {
        $adapterClass = self::$adapters[$key] ?? null;

        if (! $adapterClass) {
            throw new InvalidArgumentException("Unknown chat adapter [{$key}].");
        }

        /** @var ChatChannelAdapterContract $adapter */
        $adapter = app($adapterClass);

        return $adapter;
    }

    /**
     * @param  class-string<ChatChannelAdapterContract>  $adapterClass
     */
    public static function register(string $key, string $adapterClass): void
    {
        self::$adapters[$key] = $adapterClass;
    }
}
