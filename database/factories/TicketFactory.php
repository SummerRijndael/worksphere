<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(6),
            'description' => fake()->paragraphs(2, true),
            'status' => fake()->randomElement(TicketStatus::cases()),
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'type' => fake()->randomElement(TicketType::cases()),
            'tags' => fake()->randomElements(['frontend', 'backend', 'api', 'database', 'ui', 'performance', 'security'], rand(0, 3)),
            'reporter_id' => User::factory(),
            'assigned_to' => null,
            'team_id' => null,
            'sla_response_hours' => fake()->optional(0.3)->randomElement([4, 8, 24, 48]),
            'sla_resolution_hours' => fake()->optional(0.3)->randomElement([24, 48, 72, 168]),
            'due_date' => fake()->optional(0.5)->dateTimeBetween('now', '+2 weeks'),
        ];
    }

    /**
     * Indicate that the ticket is open.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Open,
        ]);
    }

    /**
     * Indicate that the ticket is in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::InProgress,
        ]);
    }

    /**
     * Indicate that the ticket is resolved.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Indicate that the ticket is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    /**
     * Indicate that the ticket is critical priority.
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => TicketPriority::Critical,
        ]);
    }

    /**
     * Indicate that the ticket is assigned to a user.
     */
    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to' => $user->id,
        ]);
    }

    /**
     * Indicate that the ticket belongs to a team.
     */
    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
        ]);
    }

    /**
     * Indicate that the ticket is overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Open,
            'due_date' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the ticket has SLA breached.
     */
    public function slaBreached(): static
    {
        return $this->state(fn (array $attributes) => [
            'sla_breached' => true,
        ]);
    }
}
