<?php

namespace App\Rules;

use App\Services\RecaptchaService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Recaptcha implements ValidationRule
{
    protected string $action;

    /**
     * Create a new rule instance.
     *
     * @param  string  $action  The expected reCAPTCHA action name
     */
    public function __construct(string $action = '')
    {
        $this->action = $action;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $service = app(RecaptchaService::class);

        // Skip validation if reCAPTCHA is disabled
        if (! $service->isEnabled()) {
            return;
        }

        $result = $service->verify(
            $value,
            $this->action,
            request()->ip()
        );

        if (! $result['success']) {
            $fail($result['error'] ?? 'reCAPTCHA verification failed.');
        }
    }
}
