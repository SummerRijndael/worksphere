<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Invoice>
     */
    protected $model = Invoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 5000);
        $taxRate = fake()->randomElement([0, 5, 10, 15, 20]);
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $discountAmount = fake()->boolean(30) ? fake()->randomFloat(2, 10, $subtotal * 0.1) : 0;
        $total = $subtotal + $taxAmount - $discountAmount;

        return [
            'team_id' => Team::factory(),
            'client_id' => Client::factory(),
            'project_id' => null,
            'invoice_number' => 'INV-'.date('Ym').'-'.fake()->unique()->numerify('####'),
            'status' => InvoiceStatus::Draft,
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'paid_at' => null,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total' => $total,
            'currency' => 'USD',
            'notes' => fake()->optional()->sentence(),
            'terms' => fake()->optional()->paragraph(),
            'sent_at' => null,
            'sent_to_email' => null,
            'viewed_at' => null,
            'pdf_path' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Invoice linked to a project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->id,
            'team_id' => $project->team_id,
            'client_id' => $project->client_id,
        ]);
    }

    /**
     * Invoice in draft status.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Draft,
            'sent_at' => null,
            'viewed_at' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * Invoice that has been sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Sent,
            'sent_at' => now()->subDays(fake()->numberBetween(1, 14)),
            'sent_to_email' => fake()->email(),
            'viewed_at' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * Invoice that has been viewed.
     */
    public function viewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Viewed,
            'sent_at' => now()->subDays(fake()->numberBetween(7, 21)),
            'sent_to_email' => fake()->email(),
            'viewed_at' => now()->subDays(fake()->numberBetween(1, 7)),
            'paid_at' => null,
        ]);
    }

    /**
     * Invoice that has been paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Paid,
            'sent_at' => now()->subDays(fake()->numberBetween(14, 30)),
            'sent_to_email' => fake()->email(),
            'viewed_at' => now()->subDays(fake()->numberBetween(7, 14)),
            'paid_at' => now()->subDays(fake()->numberBetween(1, 7)),
        ]);
    }

    /**
     * Invoice that is overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Overdue,
            'due_date' => now()->subDays(fake()->numberBetween(1, 30)),
            'sent_at' => now()->subDays(fake()->numberBetween(30, 60)),
            'sent_to_email' => fake()->email(),
            'viewed_at' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * Invoice that is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Cancelled,
        ]);
    }

    /**
     * Invoice with specific client.
     */
    public function forClient(Client $client): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => $client->id,
        ]);
    }

    /**
     * Invoice for specific team.
     */
    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
        ]);
    }

    /**
     * Invoice created by a specific user.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }
}
