<?php

namespace App\Notifications;

use App\Jobs\Middleware\RateLimitedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new RateLimitedMail];
    }

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public bool $requiresPasswordSetup = false,
        public ?string $actionUrl = null,
        public ?string $actionText = 'Verify Email & Get Started'
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
        // Generate Signed Verification URL if no specific action URL is provided
        $url = $this->actionUrl;

        if (! $url) {
            $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'verification.verify',
                \Illuminate\Support\Carbon::now()->addMinutes(config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        }

        return (new MailMessage)
            ->subject('Welcome to '.config('app.name'))
            ->view('emails.welcome', [
                'user' => $notifiable,
                'actionUrl' => $url,
                'actionText' => $this->actionText,
                'requiresPasswordSetup' => $this->requiresPasswordSetup,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
