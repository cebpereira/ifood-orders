<?php

declare(strict_types=1);

namespace App\Models;

use App\Ifood\Enums\OrderActionStatus;
use App\Ifood\Enums\OrderActionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant action sent to iFood. iFood answers 202 and reports the outcome
 * later as an event, so the action stays open until that event (or a timeout).
 *
 * @property int $id
 * @property int $order_id
 * @property OrderActionType $action
 * @property OrderActionStatus $status
 * @property string|null $cancellation_code
 * @property string|null $cancellation_reason
 * @property int|null $response_status
 * @property string|null $error
 * @property int|null $resolved_by_event_id
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $correlation_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class OrderAction extends Model
{
    /** How long a 202 waits for its event. Polling runs every 30s, so this covers a few cycles. */
    public const int CONFIRMATION_TIMEOUT_SECONDS = 120;

    protected $dateFormat = 'Y-m-d H:i:s.vP';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action' => OrderActionType::class,
            'status' => OrderActionStatus::class,
            'requested_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [OrderActionStatus::Pending, OrderActionStatus::Sent]);
    }

    public function resolve(OrderActionStatus $status, ?int $eventId = null, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            'resolved_by_event_id' => $eventId,
            'error' => $error ?? $this->error,
            'resolved_at' => now(),
        ])->save();
    }
}
