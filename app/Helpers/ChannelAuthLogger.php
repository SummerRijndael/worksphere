<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class ChannelAuthLogger
{
    /**
     * Wrap a channel authentication callback with logging.
     *
     * @param string $channelName
     * @param callable $callback
     * @return callable
     */
    public static function wrap(string $channelName, callable $callback): callable
    {
        return function ($user, ...$args) use ($channelName, $callback) {
            $userId = $user ? $user->getAuthIdentifier() : 'guest';
            $logArgs = json_encode($args);

            Log::channel('broadcasting')->info("Auth Request: Channel [{$channelName}] User [{$userId}] Args [{$logArgs}]");

            try {
                // Execute the original callback
                $result = $callback($user, ...$args);

                if ($result) {
                    Log::channel('broadcasting')->info("Auth Content: Channel [{$channelName}] User [{$userId}] Result [Allowed]");
                } else {
                    Log::channel('broadcasting')->warning("Auth Content: Channel [{$channelName}] User [{$userId}] Result [Denied]");
                }

                return $result;
            } catch (\Throwable $e) {
                Log::channel('broadcasting')->error("Auth Error: Channel [{$channelName}] User [{$userId}] Error [{$e->getMessage()}]");
                throw $e;
            }
        };
    }
}
