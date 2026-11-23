<?php

declare(strict_types=1);

namespace App\Models;

use App\Ifood\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Current state of an iFood order, derived from its events.
 *
 * @property int $id
 * @property string $ifood_order_id
 * @property string $merchant_id
 * @property OrderStatus $status
 * @property CarbonImmutable $first_event_at
 * @property CarbonImmutable $status_changed_at
 * @property CarbonImmutable|null $placed_at
 * @property CarbonImmutable|null $confirm_deadline_at
 * @property CarbonImmutable|null $cancellation_requested_at
 * @property string|null $cancellation_code
 * @property string|null $cancellation_reason
 * @property string|null $cancellation_origin
 * @property string|null $display_id
 * @property string|null $order_type
 * @property string|null $category
 * @property string|null $sales_channel
 * @property string|null $customer_name
 * @property string|null $total_amount
 * @property bool|null $is_test
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable|null $details_fetched_at
 * @property string|null $details_error
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    /** iFood cancels unconfirmed orders after 8 minutes (observed: 7min59s). */
    public const int CONFIRM_DEADLINE_MINUTES = 8;

    /** Keep milliseconds: iFood events can be ~150ms apart. */
    protected $dateFormat = 'Y-m-d H:i:s.vP';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'first_event_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
            'placed_at' => 'immutable_datetime',
            'confirm_deadline_at' => 'immutable_datetime',
            'cancellation_requested_at' => 'immutable_datetime',
            'total_amount' => 'decimal:2',
            'is_test' => 'boolean',
            'details' => 'array',
            'details_fetched_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<OrderTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(OrderTransition::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** @return HasMany<IfoodEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IfoodEvent::class, 'order_id', 'ifood_order_id')->orderBy('event_created_at');
    }
}
