<?php

namespace App\Notifications;

use App\Jobs\Middleware\RateLimitedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class AccountCreated extends Notification implements ShouldQueue
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
    public function __construct()
    {
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
        $url = URL::temporarySignedRoute(
            'setup-account',
            Carbon::now()->addHours(24),
            ['id' => $notifiable->public_id] // Use public_id for the route param
        );

        // Transform backend URL to frontend URL if necessary
        // Assuming frontend handles /setup-account/{id}?signature=...
        // If SPA is separate, we might need to construct string manually.
        // For this hybrid/Inertia-like setup (Vue in Laravel), the route likely points to a backend controller or a view.
        // Since we are adding a Vue view for this, we likely want a route that returns the view.
        // Or if using Vue Router with #, we constructs it differently.
        // Let's assume standard Laravel routing serving Vue app.

        return (new MailMessage)
            ->subject('Welcome to CoreSync - Setup Your Account')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('Your account has been created. Please click the button below to set up your password and log in.')
            ->action('Setup Account', $url)
            ->line('This link will expire in 24 hours.')
            ->line('Thank you for using our application!');
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
