<?php

namespace App\Notifications;

use App\Jobs\Middleware\RateLimitedMail;
use App\Models\PermissionOverride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PermissionExpiryReminder extends Notification implements ShouldQueue
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
     *
     * @param  bool  $isAdmin  Whether this notification is being sent to the admin who granted the permission
     */
    public function __construct(
        public PermissionOverride $override,
        public bool $isAdmin = false
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
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $daysLeft = $this->override->daysUntilExpiry();
        $expiryDate = $this->override->expires_at?->format('M d, Y H:i');
        $permissionType = $this->override->isGrant() ? 'granted' : 'blocked';

        $message = (new MailMessage)
            ->subject($this->getSubject())
            ->greeting("Hello {$notifiable->name},");

        if ($this->isAdmin) {
            $message->line('A permission override you created is expiring soon.');
            $message->line("**User:** {$this->override->user->name}");
        } else {
            if ($this->override->inGracePeriod()) {
                $message->line('Your temporary permission is now in its grace period and will be fully revoked soon.');
            } else {
                $message->line('Your temporary permission is expiring soon.');
            }
        }

        $message->line("**Permission:** {$this->override->permission}");
        $message->line("**Type:** {$permissionType}");

        if ($daysLeft !== null) {
            if ($daysLeft <= 0) {
                $message->line('**Status:** Expired (in grace period)');
            } elseif ($daysLeft === 1) {
                $message->line('**Expires:** Tomorrow');
            } else {
                $message->line("**Expires:** In {$daysLeft} days ({$expiryDate})");
            }
        }

        if ($this->isAdmin) {
            $message->line('You can renew this permission if needed.');
            $message->action('Manage Permission', url("/admin/users/{$this->override->user->public_id}?tab=permissions"));
        } else {
            $message->line('Contact your administrator if you need this permission extended.');
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
            'type' => 'permission_expiry_reminder',
            'override_id' => $this->override->public_id,
            'permission' => $this->override->permission,
            'permission_type' => $this->override->type,
            'user_id' => $this->override->user?->public_id,
            'user_name' => $this->override->user?->name,
            'expires_at' => $this->override->expires_at?->toIso8601String(),
            'days_until_expiry' => $this->override->daysUntilExpiry(),
            'is_admin_notification' => $this->isAdmin,
            'in_grace_period' => $this->override->inGracePeriod(),
        ];
    }

    /**
     * Get the notification subject.
     */
    protected function getSubject(): string
    {
        $daysLeft = $this->override->daysUntilExpiry();

        if ($this->override->inGracePeriod()) {
            return "Permission in Grace Period: {$this->override->permission}";
        }

        if ($daysLeft === 1) {
            return "Permission Expiring Tomorrow: {$this->override->permission}";
        }

        return "Permission Expiring in {$daysLeft} Days: {$this->override->permission}";
    }
}
