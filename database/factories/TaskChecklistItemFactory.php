<?php

namespace Database\Factories;

use App\Enums\TaskChecklistItemStatus;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaskChecklistItem>
 */
class TaskChecklistItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'text' => fake()->sentence(4),
            'status' => TaskChecklistItemStatus::Todo,
            'position' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Item that is done.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskChecklistItemStatus::Done,
            'completed_at' => now(),
        ]);
    }

    /**
     * Item that is in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskChecklistItemStatus::InProgress,
        ]);
    }
}
