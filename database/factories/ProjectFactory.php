<?php

namespace Database\Factories;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->optional(0.7)->dateTimeBetween('-1 month', '+1 month');
        $dueDate = $startDate
            ? fake()->optional(0.8)->dateTimeBetween($startDate, '+3 months')
            : null;

        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional(0.7)->paragraph(),
            'status' => fake()->randomElement(ProjectStatus::cases()),
            'priority' => fake()->randomElement(ProjectPriority::cases()),
            'start_date' => $startDate,
            'due_date' => $dueDate,
            'budget' => fake()->optional(0.5)->randomFloat(2, 1000, 100000),
            'currency' => 'USD',
            'progress_percentage' => fake()->numberBetween(0, 100),
            'created_by' => User::factory(),
        ];
    }

    /**
     * Set status to draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Draft,
            'progress_percentage' => 0,
        ]);
    }

    /**
     * Set status to active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Active,
        ]);
    }

    /**
     * Set status to completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Completed,
            'completed_at' => now(),
            'progress_percentage' => 100,
        ]);
    }

    /**
     * Set status to archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Archived,
            'archived_at' => now(),
            'archived_by' => User::factory(),
        ]);
    }

    /**
     * Set status to on hold.
     */
    public function onHold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::OnHold,
        ]);
    }

    /**
     * Make project overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Active,
            'due_date' => fake()->dateTimeBetween('-1 month', '-1 day'),
        ]);
    }

    /**
     * Set high priority.
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => ProjectPriority::High,
        ]);
    }

    /**
     * Set urgent priority.
     */
    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => ProjectPriority::Urgent,
        ]);
    }

    /**
     * With specific budget.
     */
    public function withBudget(float $amount, string $currency = 'USD'): static
    {
        return $this->state(fn (array $attributes) => [
            'budget' => $amount,
            'currency' => $currency,
        ]);
    }

    /**
     * For a specific team.
     */
    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
        ]);
    }

    /**
     * Created by specific user.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }
}
