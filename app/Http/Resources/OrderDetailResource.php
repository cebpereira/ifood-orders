<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Ifood\Enums\OrderActionType;
use App\Models\IfoodEvent;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The side sheet: items, totals, and the full event timeline, including the
 * events that were ignored and why. Customer contact data and the delivery
 * address are deliberately left out of the panel.
 *
 * @mixin Order
 */
final class OrderDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $details = $this->details ?? [];
        $transitions = $this->transitions->keyBy('ifood_event_id');

        return [
            ...(new OrderResource($this->resource))->toArray($request),
            'category' => $this->category,
            'details_error' => $this->details_error,
            'items' => array_values(array_map(fn (mixed $item): array => [
                'name' => self::str(data_get($item, 'name')) ?? 'Item',
                'quantity' => self::num(data_get($item, 'quantity')),
                'total_price' => self::num(data_get($item, 'totalPrice')),
                'observations' => self::str(data_get($item, 'observations')),
                'options' => array_values(array_map(fn (mixed $option): array => [
                    'name' => self::str(data_get($option, 'name')) ?? '',
                    'quantity' => self::num(data_get($option, 'quantity')),
                ], self::list(data_get($item, 'options')))),
            ], self::list($details['items'] ?? []))),
            'totals' => [
                'sub_total' => self::num(data_get($details, 'total.subTotal')),
                'delivery_fee' => self::num(data_get($details, 'total.deliveryFee')),
                'benefits' => self::num(data_get($details, 'total.benefits')),
                'additional_fees' => self::num(data_get($details, 'total.additionalFees')),
                'order_amount' => self::num(data_get($details, 'total.orderAmount')),
            ],
            'payments' => array_values(array_map(fn (mixed $method): array => [
                'method' => self::str(data_get($method, 'method')),
                'type' => self::str(data_get($method, 'type')),
                'value' => self::num(data_get($method, 'value')),
                'prepaid' => (bool) data_get($method, 'prepaid'),
            ], self::list(data_get($details, 'payments.methods')))),
            'delivered_by' => self::str(data_get($details, 'delivery.deliveredBy')),
            'available_actions' => array_values(array_map(
                fn (OrderActionType $type): array => ['action' => $type->value, 'label' => $type->label()],
                array_filter(OrderActionType::cases(), fn (OrderActionType $type): bool => $type->allowedFor($this->status, $this->order_type)),
            )),
            'actions' => $this->actions->take(10)->map(fn (OrderAction $action): array => [
                'id' => $action->id,
                'action' => $action->action->value,
                'label' => $action->action->label(),
                'status' => $action->status->value,
                'error' => $action->error,
                'cancellation_code' => $action->cancellation_code,
                'requested_at' => $action->requested_at->toIso8601ZuluString('millisecond'),
                'resolved_at' => $action->resolved_at?->toIso8601ZuluString('millisecond'),
            ])->values()->all(),
            'timeline' => $this->events->map(function (IfoodEvent $event) use ($transitions): array {
                /** @var OrderTransition|null $transition */
                $transition = $transitions->get($event->id);

                return [
                    'id' => $event->id,
                    'code' => $event->code,
                    'full_code' => $event->full_code,
                    'occurred_at' => $event->event_created_at->toIso8601ZuluString('millisecond'),
                    'received_at' => $event->created_at->toIso8601ZuluString('millisecond'),
                    'status' => $event->status->value,
                    'ignore_reason' => $event->ignore_reason,
                    'attempts' => $event->attempts,
                    'last_error' => $event->last_error,
                    'from_status' => $transition?->from_status?->value,
                    'to_status' => $transition?->to_status?->value,
                    'applied' => $transition?->applied,
                    'origin' => self::str(data_get($event->metadata, 'ORIGIN') ?? data_get($event->metadata, 'CANCEL_ORIGIN')),
                ];
            })->values()->all(),
        ];
    }

    /** @return array<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
