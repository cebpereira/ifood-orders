<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a ticket on the board (or a row in the list) needs.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ifood_order_id' => $this->ifood_order_id,
            'display_id' => $this->display_id,
            'status' => $this->status->value,
            'order_type' => $this->order_type,
            'sales_channel' => $this->sales_channel,
            'customer_name' => $this->customer_name,
            'total_amount' => $this->total_amount === null ? null : (float) $this->total_amount,
            'is_test' => $this->is_test,
            'placed_at' => $this->placed_at?->toIso8601ZuluString('millisecond'),
            'first_event_at' => $this->first_event_at->toIso8601ZuluString('millisecond'),
            'status_changed_at' => $this->status_changed_at->toIso8601ZuluString('millisecond'),
            'confirm_deadline_at' => $this->confirm_deadline_at?->toIso8601ZuluString('millisecond'),
            'cancellation_requested_at' => $this->cancellation_requested_at?->toIso8601ZuluString('millisecond'),
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_origin' => $this->cancellation_origin,
            'open_action' => $this->whenLoaded('openAction', fn () => $this->openAction ? [
                'action' => $this->openAction->action->value,
                'label' => $this->openAction->action->label(),
                'status' => $this->openAction->status->value,
            ] : null),
            'details_state' => match (true) {
                $this->details_fetched_at !== null => 'ready',
                $this->details_error !== null => 'failed',
                default => 'pending',
            },
        ];
    }
}
