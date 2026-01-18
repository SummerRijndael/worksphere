<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<InvoiceItem>
     */
    protected $model = InvoiceItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 1, 10);
        $unitPrice = fake()->randomFloat(2, 25, 500);

        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->sentence(fake()->numberBetween(3, 8)),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => round($quantity * $unitPrice, 2),
            'sort_order' => 0,
        ];
    }

    /**
     * Item for a specific invoice.
     */
    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_id' => $invoice->id,
        ]);
    }

    /**
     * Hourly service item.
     */
    public function hourlyService(): static
    {
        $hours = fake()->randomFloat(2, 1, 40);
        $rate = fake()->randomElement([50, 75, 100, 125, 150, 200]);

        return $this->state(fn (array $attributes) => [
            'description' => fake()->randomElement([
                'Development hours',
                'Consulting hours',
                'Design work',
                'Project management',
                'Support hours',
            ]),
            'quantity' => $hours,
            'unit_price' => $rate,
            'total' => round($hours * $rate, 2),
        ]);
    }

    /**
     * Fixed-price item.
     */
    public function fixedPrice(): static
    {
        $price = fake()->randomFloat(2, 100, 2000);

        return $this->state(fn (array $attributes) => [
            'description' => fake()->randomElement([
                'Website setup fee',
                'Monthly maintenance',
                'Hosting package',
                'SSL certificate',
                'Domain registration',
                'Plugin license',
            ]),
            'quantity' => 1,
            'unit_price' => $price,
            'total' => $price,
        ]);
    }
}
