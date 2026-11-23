<?php

declare(strict_types=1);

namespace App\Models;

use App\Ifood\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail: what each event did to its order, including the events that
 * were deliberately not applied and why.
 *
 * @property int $id
 * @property int $order_id
 * @property int $ifood_event_id
 * @property string $event_code
 * @property OrderStatus|null $from_status
 * @property OrderStatus|null $to_status
 * @property bool $applied
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
final class OrderTransition extends Model
{
    public const UPDATED_AT = null;

    /** Keep milliseconds: iFood events can be ~150ms apart. */
    protected $dateFormat = 'Y-m-d H:i:s.vP';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'applied' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<IfoodEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(IfoodEvent::class, 'ifood_event_id');
    }
}
