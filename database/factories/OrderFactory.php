<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Ifood\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic orders only (tests and local demo data), never real payloads.
 *
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $placedAt = now()->subMinutes(fake()->numberBetween(1, 120))->toImmutable();

        return [
            'ifood_order_id' => fake()->uuid(),
            'merchant_id' => '00000000-0000-0000-0000-000000000001',
            'status' => OrderStatus::Placed,
            'first_event_at' => $placedAt,
            'status_changed_at' => $placedAt,
            'placed_at' => $placedAt,
            'confirm_deadline_at' => $placedAt->addMinutes(Order::CONFIRM_DEADLINE_MINUTES),
            'display_id' => (string) fake()->numberBetween(1000, 9999),
            'order_type' => 'DELIVERY',
            'category' => 'FOOD',
            'sales_channel' => 'IFOOD',
            'customer_name' => fake()->name(),
            'total_amount' => fake()->randomFloat(2, 15, 180),
            'is_test' => true,
        ];
    }

    public function status(OrderStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
