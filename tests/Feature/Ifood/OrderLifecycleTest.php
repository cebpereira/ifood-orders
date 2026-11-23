<?php

declare(strict_types=1);

use App\Ifood\Enums\IfoodEventStatus;
use App\Ifood\Enums\OrderStatus;
use App\Jobs\FetchOrderDetailsJob;
use App\Jobs\ProcessIfoodEventJob;
use App\Models\IfoodEvent;
use App\Models\Order;
use Illuminate\Support\Facades\Bus;

beforeEach(fn () => Bus::fake([FetchOrderDetailsJob::class]));

/** Run the event jobs in the given arrival order. */
function process(IfoodEvent ...$events): void
{
    foreach ($events as $event) {
        ProcessIfoodEventJob::dispatchSync($event->id);
    }
}

function theOrder(): Order
{
    return Order::query()->where('ifood_order_id', TEST_ORDER_ID)->sole();
}

it('follows the happy path and records every transition', function (): void {
    process(
        eventFor('PLC', '2026-11-23 18:00:00.000'),
        eventFor('CFM', '2026-11-23 18:01:00.000'),
        eventFor('PRS', '2026-11-23 18:02:00.000'),
        eventFor('RTP', '2026-11-23 18:10:00.000'),
        eventFor('DSP', '2026-11-23 18:11:00.000'),
        eventFor('CON', '2026-11-23 18:40:00.000'),
    );

    $order = theOrder();
    expect($order->status)->toBe(OrderStatus::Concluded)
        ->and($order->status_changed_at->format('H:i'))->toBe('18:40')
        ->and($order->transitions->pluck('applied')->all())->toBe([true, true, true, true, true, true])
        ->and($order->transitions->pluck('to_status')->map->value->all())
        ->toBe(['PLACED', 'CONFIRMED', 'PREPARATION_STARTED', 'READY_TO_PICKUP', 'DISPATCHED', 'CONCLUDED']);
});

it('sets the 8-minute confirmation deadline from PLC', function (): void {
    process(eventFor('PLC', '2026-11-23 18:12:01.307'));

    expect(theOrder()->confirm_deadline_at->format('H:i:s.v'))->toBe('18:20:01.307');
});

it('fetches order details once, when the order is first seen', function (): void {
    process(eventFor('PLC', '2026-11-23 18:00:00'), eventFor('CFM', '2026-11-23 18:01:00'));

    Bus::assertDispatchedTimes(FetchOrderDetailsJob::class, 1);
});

describe('out of order', function (): void {
    it('reaches the same state when events arrive reversed', function (): void {
        $plc = eventFor('PLC', '2026-11-23 18:00:00.000');
        $cfm = eventFor('CFM', '2026-11-23 18:01:00.000');
        $dsp = eventFor('DSP', '2026-11-23 18:05:00.000');

        process($dsp, $cfm, $plc);

        $order = theOrder();
        expect($order->status)->toBe(OrderStatus::Dispatched)
            ->and($order->placed_at?->format('H:i'))->toBe('18:00')
            ->and($order->first_event_at->format('H:i'))->toBe('18:00')
            ->and($cfm->refresh()->status)->toBe(IfoodEventStatus::Ignored)
            ->and($cfm->ignore_reason)->toBe('not_forward')
            ->and($plc->refresh()->ignore_reason)->toBe('not_forward');
    });

    it('keeps the deadline from a PLC that arrives late', function (): void {
        process(eventFor('CFM', '2026-11-23 18:01:00'), eventFor('PLC', '2026-11-23 18:00:00'));

        expect(theOrder())
            ->status->toBe(OrderStatus::Confirmed)
            ->and(theOrder()->confirm_deadline_at?->format('H:i'))->toBe('18:08');
    });

    it('waits for the order when a CAR overtakes every status event', function (): void {
        $car = eventFor('CAR', '2026-11-23 18:05:00', metadata: ['reason_code' => '503']);

        process($car);

        expect(Order::count())->toBe(0)
            ->and($car->refresh()->status)->toBe(IfoodEventStatus::Received)
            ->and($car->attempts)->toBe(0);

        process(eventFor('PLC', '2026-11-23 18:00:00'), $car);

        expect($car->refresh()->status)->toBe(IfoodEventStatus::Processed)
            ->and(theOrder()->cancellation_code)->toBe('503');
    });
});

describe('late and invalid events', function (): void {
    it('does not reopen a cancelled order with a late CFM', function (): void {
        process(eventFor('PLC', '2026-11-23 18:00:00'), eventFor('CAN', '2026-11-23 18:08:00'));
        $lateCfm = eventFor('CFM', '2026-11-23 18:07:59');

        process($lateCfm);

        $lateCfm->refresh();
        expect(theOrder()->status)->toBe(OrderStatus::Cancelled)
            ->and($lateCfm->status)->toBe(IfoodEventStatus::Ignored)
            ->and($lateCfm->ignore_reason)->toBe('order_terminal')
            // History is ordered by event time: the late CFM sits before the CAN.
            ->and(theOrder()->transitions->pluck('event_code')->all())->toBe(['PLC', 'CFM', 'CAN'])
            ->and(theOrder()->transitions->firstWhere('event_code', 'CFM'))
            ->applied->toBeFalse()
            ->from_status->toBe(OrderStatus::Cancelled)
            ->reason->toBe('order_terminal');
    });

    it('keeps the cancellation details from the CAN that cancelled the order', function (): void {
        process(
            eventFor('PLC', '2026-11-23 18:12:01'),
            eventFor('CAR', '2026-11-23 18:20:00.847', metadata: ['ORIGIN' => 'SYSTEM', 'reason_code' => '902', 'details' => 'O pedido não foi confirmado pela loja']),
            eventFor('CAN', '2026-11-23 18:20:01.030', metadata: ['CANCEL_CODE' => '902', 'CANCEL_CODE_DESCRIPTION' => 'O pedido não foi confirmado pela loja', 'CANCEL_ORIGIN' => 'SCHEDULER']),
        );

        expect(theOrder())
            ->status->toBe(OrderStatus::Cancelled)
            ->cancellation_requested_at->not->toBeNull()
            ->cancellation_code->toBe('902')
            ->cancellation_origin->toBe('SCHEDULER');
    });

    it('cancels without a prior CAR (seen on the real API)', function (): void {
        process(
            eventFor('PLC', '2026-11-23 18:00:00'),
            eventFor('CFM', '2026-11-23 18:01:00'),
            eventFor('DSP', '2026-11-23 18:02:00'),
            eventFor('CAN', '2026-11-23 18:03:00', metadata: ['CANCEL_CODE' => '501', 'CANCEL_ORIGIN' => 'RESTAURANT', 'CANCEL_STAGE' => '[DISPATCHED]']),
        );

        expect(theOrder())
            ->status->toBe(OrderStatus::Cancelled)
            ->cancellation_requested_at->toBeNull()
            ->cancellation_origin->toBe('RESTAURANT');
    });

    it('processes CAR and CARF without changing the status', function (): void {
        process(eventFor('PLC', '2026-11-23 18:00:00'), eventFor('CFM', '2026-11-23 18:01:00'));
        $car = eventFor('CAR', '2026-11-23 18:02:00', metadata: ['reason_code' => '503']);
        $carf = eventFor('CARF', '2026-11-23 18:02:01');

        process($car, $carf);

        expect(theOrder()->status)->toBe(OrderStatus::Confirmed)
            ->and($car->refresh()->status)->toBe(IfoodEventStatus::Processed)
            ->and($carf->refresh()->status)->toBe(IfoodEventStatus::Processed)
            ->and(theOrder()->transitions->where('reason', 'no_status_change'))->toHaveCount(2);
    });
});

it('does not duplicate history when an event is reprocessed by hand', function (): void {
    $plc = eventFor('PLC', '2026-11-23 18:00:00');
    process($plc);
    $plc->forceFill(['status' => IfoodEventStatus::Received])->save();

    process($plc);

    expect(theOrder()->transitions)->toHaveCount(1);
});

it('keeps orders apart', function (): void {
    $other = '0b000000-0000-4000-8000-0000000000bb';

    process(
        eventFor('PLC', '2026-11-23 18:00:00'),
        eventFor('PLC', '2026-11-23 18:00:00', $other),
        eventFor('CAN', '2026-11-23 18:01:00', $other),
    );

    expect(theOrder()->status)->toBe(OrderStatus::Placed)
        ->and(Order::where('ifood_order_id', $other)->sole()->status)->toBe(OrderStatus::Cancelled);
});
