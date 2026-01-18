<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(TaskStatus::cases()),
            'priority' => fake()->numberBetween(1, 5),
            'due_date' => fake()->optional(0.7)->dateTimeBetween('now', '+30 days'),
            'estimated_hours' => fake()->optional(0.5)->randomFloat(2, 1, 40),
            'sort_order' => 0,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Set the task status to draft.
     */
    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Draft,
        ]);
    }

    /**
     * Set the task status to open.
     */
    public function open(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Open,
        ]);
    }

    /**
     * Set the task status to in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::InProgress,
            'started_at' => now(),
        ]);
    }

    /**
     * Set the task status to submitted.
     */
    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Submitted,
            'started_at' => now()->subHours(2),
            'submitted_at' => now(),
        ]);
    }

    /**
     * Set the task status to in QA.
     */
    public function inQa(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::InQa,
            'started_at' => now()->subHours(3),
            'submitted_at' => now()->subHour(),
        ]);
    }

    /**
     * Set the task status to approved.
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Approved,
            'started_at' => now()->subHours(4),
            'submitted_at' => now()->subHours(2),
            'approved_at' => now(),
        ]);
    }

    /**
     * Set the task status to completed.
     */
    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Completed,
            'started_at' => now()->subDays(2),
            'submitted_at' => now()->subDay(),
            'approved_at' => now()->subHours(12),
            'completed_at' => now(),
        ]);
    }

    /**
     * Set the task status to archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Archived,
            'archived_at' => now(),
            'archived_by' => $attributes['created_by'] ?? User::factory(),
        ]);
    }

    /**
     * Set task as overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'due_date' => now()->subDays(fake()->numberBetween(1, 7)),
            'status' => TaskStatus::InProgress,
        ]);
    }

    /**
     * Assign the task to a user.
     */
    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to' => $user->id,
            'assigned_by' => $attributes['created_by'] ?? User::factory(),
            'assigned_at' => now(),
        ]);
    }

    /**
     * Set high priority.
     */
    public function highPriority(): static
    {
        return $this->state(fn () => [
            'priority' => 5,
        ]);
    }

    /**
     * Set low priority.
     */
    public function lowPriority(): static
    {
        return $this->state(fn () => [
            'priority' => 1,
        ]);
    }

    /**
     * Create as a subtask.
     */
    public function subtaskOf(Task $parent): static
    {
        return $this->state(fn () => [
            'parent_id' => $parent->id,
            'project_id' => $parent->project_id,
        ]);
    }
}
