<?php

namespace App\Notifications;

use App\Jobs\Middleware\RateLimitedMail;
use App\Models\PermissionOverride;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PermissionChangeNotification extends Notification implements ShouldQueue
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
        public PermissionOverride $override,
        public string $action,
        public User $performedBy
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
        $subject = $this->getSubject();
        $actionLabel = ucfirst($this->action);
        $permissionType = $this->override->isGrant() ? 'granted' : 'blocked';

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line("A permission override has been {$this->action}.");

        $message->line('**Details:**');
        $message->line("- **User:** {$this->override->user->name}");
        $message->line("- **Permission:** {$this->override->permission}");
        $message->line("- **Type:** {$permissionType}");
        $message->line("- **Scope:** {$this->override->scope}");

        if ($this->override->is_temporary) {
            $message->line("- **Expires:** {$this->override->expires_at?->format('M d, Y H:i')}");
        }

        $message->line("- **Reason:** {$this->override->reason}");
        $message->line("- **By:** {$this->performedBy->name}");

        $message->action('View User Permissions', url("/admin/users/{$this->override->user->public_id}"));

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
            'type' => 'permission_change',
            'action' => $this->action,
            'override_id' => $this->override->public_id,
            'permission' => $this->override->permission,
            'permission_type' => $this->override->type,
            'user_id' => $this->override->user->public_id,
            'user_name' => $this->override->user->name,
            'performed_by_id' => $this->performedBy->public_id,
            'performed_by_name' => $this->performedBy->name,
            'reason' => $this->override->reason,
        ];
    }

    /**
     * Get the notification subject.
     */
    protected function getSubject(): string
    {
        $type = $this->override->isGrant() ? 'Grant' : 'Block';

        return match ($this->action) {
            'grant', 'block' => "Permission {$type} Created for {$this->override->user->name}",
            'revoke' => "Permission Override Revoked for {$this->override->user->name}",
            'renew' => "Permission Override Renewed for {$this->override->user->name}",
            default => "Permission Change: {$this->override->user->name}",
        };
    }
}
