<?php

namespace App\Services\Dialer;

use App\Contracts\DialerAdapterContract;
use App\Services\Dialer\Adapters\DemoDialerAdapter;
use App\Services\Dialer\Adapters\TwilioDialerAdapter;
use InvalidArgumentException;

class DialerAdapterManager
{
    public function __construct(
        protected DemoDialerAdapter $demoAdapter,
        protected TwilioDialerAdapter $twilioAdapter,
    ) {}

    public function default(): DialerAdapterContract
    {
        return $this->driver((string) config('dialer.default_adapter', 'demo'));
    }

    public function driver(string $key): DialerAdapterContract
    {
        return match ($key) {
            'demo' => $this->demoAdapter,
            'twilio' => $this->twilioAdapter,
            default => throw new InvalidArgumentException("Unsupported dialer adapter [{$key}]."),
        };
    }
}
