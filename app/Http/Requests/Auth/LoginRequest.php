<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // Either email or public_id is required
            'email' => ['required_without:public_id', 'nullable', 'string'],
            'public_id' => ['required_without:email', 'nullable', 'string', 'uuid'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];

        // Add reCAPTCHA validation if enabled
        if (config('recaptcha.enabled')) {
            $rules['recaptcha_token'] = ['required_without:recaptcha_v2_token', 'nullable', 'string'];
            $rules['recaptcha_v2_token'] = ['nullable', 'string'];
        }

        return $rules;
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Resolve user - either by public_id or email/username
        $user = null;
        $loginIdentifier = null;

        if ($this->filled('public_id')) {
            // Identity login mode - lookup by public_id
            $user = User::where('public_id', $this->input('public_id'))->first();
            $loginIdentifier = $this->input('public_id');

            if ($user) {
                // Attempt authentication with the found user's email
                $credentials = [
                    'email' => $user->email,
                    'password' => $this->input('password'),
                ];
            }
        } else {
            // Traditional login with email/username
            $loginIdentifier = $this->input('email');
            $loginType = filter_var($loginIdentifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

            $credentials = [
                $loginType => $loginIdentifier,
                'password' => $this->input('password'),
            ];
        }

        if (! isset($credentials) || ! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        $identifier = $this->input('public_id') ?? $this->input('email') ?? '';

        return Str::transliterate(Str::lower($identifier).'|'.$this->ip());
    }
}
