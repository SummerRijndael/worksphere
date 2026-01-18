<?php

namespace App\Notifications;

use App\Jobs\Middleware\RateLimitedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new RateLimitedMail];
    }

    public function __construct(
        protected string $code
    ) {
        $this->onQueue('mail');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your CoreSync Verification Code')
            ->greeting('Hello!')
            ->line('You are receiving this email because we received a two-factor authentication request for your account.')
            ->line('Your verification code is:')
            ->line("**{$this->code}**")
            ->line('This code will expire in 10 minutes.')
            ->line('If you did not request this code, please ignore this email or contact support if you have concerns.')
            ->salutation('Best regards,')
            ->line('The CoreSync Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'code' => $this->code,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ];
    }
}
