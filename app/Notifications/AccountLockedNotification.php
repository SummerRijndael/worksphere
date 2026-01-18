<?php

namespace App\Notifications;

use App\Jobs\Middleware\RateLimitedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountLockedNotification extends Notification implements ShouldQueue
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
        public string $duration,
        public bool $isPermanent = false,
        public int $strikes = 1
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
        $message = (new MailMessage)
            ->subject($this->isPermanent ? 'Account Banned - Security Alert' : 'Account Suspended - Security Alert')
            ->greeting('Hello '.$notifiable->display_name.',')
            ->line('We detected multiple failed login attempts or suspicious activity on your account.');

        if ($this->isPermanent) {
            return $message
                ->line('Due to repeated security violations, your account has been permanently banned.')
                ->line('If you believe this is an error, please contact our support team immediately.')
                ->action('Contact Support', url('/support'));
        }

        $message->line("Your account has been suspended for **{$this->duration}**.");

        if ($this->strikes >= 3) {
            $message->line('We strongly recommend resetting your password immediately after the suspension expires.')
                ->action('Reset Password', url('/forgot-password'));
        } else {
            $message->line('This is a temporary security measure to protect your account.')
                ->line('Access will be automatically restored after the suspension period.');
        }

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'duration' => $this->duration,
            'is_permanent' => $this->isPermanent,
            'strikes' => $this->strikes,
        ];
    }
}
