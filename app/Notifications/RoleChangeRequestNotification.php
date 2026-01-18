<?php

namespace App\Notifications;

use App\Models\RoleChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RoleChangeRequestNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public RoleChangeRequest $request,
        public string $action = 'created'
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->getSubject();

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},");

        if ($this->action === 'created') {
            $message->line('A new role change request requires your approval.');
            $message->line("**Request Type:** {$this->request->getTypeLabel()}");

            if ($this->request->targetRole) {
                $message->line("**Target Role:** {$this->request->targetRole->name}");
            }

            $message->line("**Requested By:** {$this->request->requestedByUser->name}");
            $message->line("**Reason:** {$this->request->reason}");
            $message->line("**Approvals Needed:** {$this->request->required_approvals}");
            $message->line("**Expires:** {$this->request->expires_at?->format('M d, Y H:i')}");

            $message->action('Review Request', url("/admin/system/roles-permissions?tab=approvals&id={$this->request->public_id}"));
        } elseif ($this->action === 'approved') {
            $message->line('A role change request has been fully approved and applied.');
            $message->line("**Request Type:** {$this->request->getTypeLabel()}");
            $message->action('View Details', url('/admin/system/roles-permissions?tab=approvals'));
        } elseif ($this->action === 'rejected') {
            $message->line('A role change request has been rejected.');
            $message->line("**Request Type:** {$this->request->getTypeLabel()}");
            $message->action('View Details', url('/admin/system/roles-permissions?tab=approvals'));
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
            'type' => 'role_change_request',
            'action' => $this->action,
            'request_id' => $this->request->public_id,
            'request_type' => $this->request->type,
            'request_type_label' => $this->request->getTypeLabel(),
            'target_role' => $this->request->targetRole?->name,
            'requested_by_id' => $this->request->requestedByUser->public_id,
            'requested_by_name' => $this->request->requestedByUser->name,
            'status' => $this->request->status,
            'required_approvals' => $this->request->required_approvals,
            'current_approvals' => $this->request->currentApprovalCount(),
            'message' => $this->getSubject(),
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'data' => $this->toArray($notifiable),
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
            'type' => 'App\\Notifications\\RoleChangeRequestNotification',
        ]);
    }

    /**
     * Get the notification subject.
     */
    protected function getSubject(): string
    {
        return match ($this->action) {
            'created' => "Role Change Request: {$this->request->getTypeLabel()} - Approval Needed",
            'approved' => "Role Change Request Approved: {$this->request->getTypeLabel()}",
            'rejected' => "Role Change Request Rejected: {$this->request->getTypeLabel()}",
            default => 'Role Change Request Update',
        };
    }
}
