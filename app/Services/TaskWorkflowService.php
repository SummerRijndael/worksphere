<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\TaskStatus;
use App\Models\QaCheckTemplate;
use App\Models\Task;
use App\Models\TaskQaReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TaskWorkflowService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Assign a task to a user.
     */
    public function assignTask(Task $task, User $assignee, User $assignedBy): Task
    {
        $previousAssignee = $task->assignee;

        $task->update([
            'assigned_to' => $assignee->id,
            'assigned_by' => $assignedBy->id,
            'assigned_at' => now(),
        ]);

        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::TaskManagement,
            $task,
            $assignedBy,
            ['assigned_to' => $previousAssignee?->id],
            ['assigned_to' => $assignee->id],
            ['task_title' => $task->title, 'assignee_name' => $assignee->name]
        );

        return $task->fresh();
    }

    /**
     * Start working on a task (move from Open to InProgress).
     */
    public function startTask(Task $task, User $user): bool
    {
        if (! $task->canTransitionTo(TaskStatus::InProgress)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::InProgress, $user, 'Work started');

        if ($result) {
            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'started']
            );
        }

        return $result;
    }

    /**
     * Submit task for QA review.
     */
    public function submitForQa(Task $task, User $user, ?string $notes = null): bool
    {
        if (! $task->hasAllChecklistItemsComplete()) {
            return false;
        }

        if (! $task->canTransitionTo(TaskStatus::Submitted)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::Submitted, $user, $notes ?? 'Submitted for QA review');

        if ($result) {
            $task->update(['submitted_at' => now()]);

            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'submitted_for_qa']
            );
        }

        return $result;
    }

    /**
     * Start QA review for a task.
     */
    public function startQaReview(Task $task, User $reviewer, ?QaCheckTemplate $template = null): ?TaskQaReview
    {
        if (! $task->canTransitionTo(TaskStatus::InQa)) {
            return null;
        }

        return DB::transaction(function () use ($task, $reviewer, $template) {
            $task->transitionTo(TaskStatus::InQa, $reviewer, 'QA review started');

            $review = TaskQaReview::create([
                'task_id' => $task->id,
                'reviewer_id' => $reviewer->id,
                'qa_check_template_id' => $template?->id,
                'status' => 'in_progress',
            ]);

            $this->auditService->log(
                AuditAction::Created,
                AuditCategory::TaskManagement,
                $review,
                $reviewer,
                null,
                null,
                ['task_id' => $task->id, 'task_title' => $task->title]
            );

            return $review;
        });
    }

    /**
     * Complete QA review and approve or reject the task.
     */
    public function completeQaReview(
        TaskQaReview $review,
        array $results,
        User $reviewer,
        bool $approved,
        ?string $notes = null
    ): bool {
        $task = $review->task;
        $targetStatus = $approved ? TaskStatus::Approved : TaskStatus::Rejected;

        if (! $task->canTransitionTo($targetStatus)) {
            return false;
        }

        return DB::transaction(function () use ($review, $results, $task, $targetStatus, $reviewer, $approved, $notes) {
            // Complete the review with results
            $review->complete($results, $notes);

            // Transition task status
            $statusNotes = $approved ? 'QA approved' : 'QA rejected: '.($notes ?? 'Issues found');
            $task->transitionTo($targetStatus, $reviewer, $statusNotes);

            if ($approved) {
                $task->update(['approved_at' => now()]);
            }

            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $reviewer,
                null,
                null,
                [
                    'task_title' => $task->title,
                    'action' => $approved ? 'qa_approved' : 'qa_rejected',
                    'review_notes' => $notes,
                ]
            );

            return true;
        });
    }

    /**
     * Send task to client for review.
     */
    public function sendToClient(Task $task, User $user, ?string $message = null): bool
    {
        if (! $task->canTransitionTo(TaskStatus::SentToClient)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::SentToClient, $user, $message ?? 'Sent to client for review');

        if ($result) {
            $task->update(['sent_to_client_at' => now()]);

            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'sent_to_client']
            );

            // TODO: Send notification to client
        }

        return $result;
    }

    /**
     * Record client approval of task.
     */
    public function clientApprove(Task $task, User $user, ?string $notes = null): bool
    {
        if (! $task->canTransitionTo(TaskStatus::ClientApproved)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::ClientApproved, $user, $notes ?? 'Approved by client');

        if ($result) {
            $task->update(['client_approved_at' => now()]);

            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'client_approved']
            );
        }

        return $result;
    }

    /**
     * Record client rejection of task.
     */
    public function clientReject(Task $task, User $user, string $reason): bool
    {
        if (! $task->canTransitionTo(TaskStatus::ClientRejected)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::ClientRejected, $user, 'Client rejected: '.$reason);

        if ($result) {
            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'client_rejected', 'reason' => $reason]
            );
        }

        return $result;
    }

    /**
     * Return task to in progress (after rejection).
     */
    public function returnToProgress(Task $task, User $user, ?string $notes = null): bool
    {
        if (! $task->canTransitionTo(TaskStatus::InProgress)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::InProgress, $user, $notes ?? 'Returned to in progress');

        if ($result) {
            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'returned_to_progress']
            );
        }

        return $result;
    }

    /**
     * Complete a task.
     */
    public function completeTask(Task $task, User $user, ?string $notes = null): bool
    {
        if (! $task->canTransitionTo(TaskStatus::Completed)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::Completed, $user, $notes ?? 'Task completed');

        if ($result) {
            $task->update(['completed_at' => now()]);

            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'completed']
            );
        }

        return $result;
    }

    /**
     * Archive a task.
     */
    public function archiveTask(Task $task, User $user): bool
    {
        if (! $task->canTransitionTo(TaskStatus::Archived)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::Archived, $user, 'Task archived');

        if ($result) {
            $task->update(['archived_at' => now(), 'archived_by' => $user->id]);

            $this->auditService->log(
                AuditAction::Archived,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title]
            );
        }

        return $result;
    }

    /**
     * Toggle On Hold status.
     */
    public function toggleHold(Task $task, User $user, ?string $notes = null): bool
    {
        $targetStatus = $task->status === TaskStatus::OnHold ? TaskStatus::InProgress : TaskStatus::OnHold;
        
        // If coming back from hold, we might return to Open if it wasn't started?
        // But simplified logic: OnHold <-> InProgress (or previous state)
        // For now, let's assume OnHold usually goes back to InProgress or Open.
        // Based on allowedTransitions: OnHold -> InProgress, Open.
        
        if ($targetStatus === TaskStatus::InProgress && ! $task->canTransitionTo(TaskStatus::InProgress)) {
             // Fallback to Open if InProgress is not allowed (e.g. from Draft -> OnHold -> Open)
             if ($task->canTransitionTo(TaskStatus::Open)) {
                 $targetStatus = TaskStatus::Open;
             } else {
                 return false;
             }
        }

        if (! $task->canTransitionTo($targetStatus)) {
            return false;
        }

        return $task->transitionTo($targetStatus, $user, $notes ?? ($targetStatus === TaskStatus::OnHold ? 'Put on hold' : 'Resumed from hold'));
    }

    /**
     * Send task to PM for review.
     */
    public function sendToPm(Task $task, User $user, ?string $notes = null): bool
    {
        if (! $task->canTransitionTo(TaskStatus::PmReview)) {
            return false;
        }

        $result = $task->transitionTo(TaskStatus::PmReview, $user, $notes ?? 'Sent to PM for review');

        if ($result) {
             $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::TaskManagement,
                $task,
                $user,
                null,
                null,
                ['task_title' => $task->title, 'action' => 'sent_to_pm']
            );
        }

        return $result;
    }

    /**
     * Get available transitions for a task.
     */
    public function getAvailableTransitions(Task $task): array
    {
        return array_map(
            fn (TaskStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ],
            $task->status->allowedTransitions()
        );
    }
}
