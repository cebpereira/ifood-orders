<?php

declare(strict_types=1);

use App\Ifood\Enums\EventCode;
use App\Ifood\Enums\OrderStatus;
use App\Ifood\Orders\Decision;
use App\Ifood\Orders\OrderStateMachine;

function decide(?OrderStatus $current, EventCode $code): Decision
{
    return (new OrderStateMachine)->decide($current, $code);
}

it('applies forward transitions, including skips', function (?OrderStatus $from, EventCode $code, OrderStatus $to): void {
    $decision = decide($from, $code);

    expect($decision->applies)->toBeTrue()
        ->and($decision->to)->toBe($to)
        ->and($decision->reason)->toBeNull();
})->with([
    'new order' => [null, EventCode::Placed, OrderStatus::Placed],
    'first event is not PLC' => [null, EventCode::Confirmed, OrderStatus::Confirmed],
    'PLC → CFM' => [OrderStatus::Placed, EventCode::Confirmed, OrderStatus::Confirmed],
    'CFM → PRS' => [OrderStatus::Confirmed, EventCode::PreparationStarted, OrderStatus::PreparationStarted],
    'PRS → RTP' => [OrderStatus::PreparationStarted, EventCode::ReadyToPickup, OrderStatus::ReadyToPickup],
    'RTP → DSP' => [OrderStatus::ReadyToPickup, EventCode::Dispatched, OrderStatus::Dispatched],
    'DSP → CON' => [OrderStatus::Dispatched, EventCode::Concluded, OrderStatus::Concluded],
    'CFM → DSP (seen on the real API)' => [OrderStatus::Confirmed, EventCode::Dispatched, OrderStatus::Dispatched],
    'RTP → CON (dine-in, no dispatch)' => [OrderStatus::ReadyToPickup, EventCode::Concluded, OrderStatus::Concluded],
]);

it('cancels from any non-terminal status', function (?OrderStatus $from): void {
    expect(decide($from, EventCode::Cancelled))
        ->applies->toBeTrue()
        ->to->toBe(OrderStatus::Cancelled);
})->with([null, OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::PreparationStarted, OrderStatus::ReadyToPickup, OrderStatus::Dispatched]);

it('ignores events that do not move the order forward', function (OrderStatus $from, EventCode $code): void {
    expect(decide($from, $code))
        ->applies->toBeFalse()
        ->reason->toBe(Decision::NOT_FORWARD);
})->with([
    'late PLC' => [OrderStatus::Confirmed, EventCode::Placed],
    'repeated CFM' => [OrderStatus::Confirmed, EventCode::Confirmed],
    'CFM after PRS' => [OrderStatus::PreparationStarted, EventCode::Confirmed],
    'PRS after DSP' => [OrderStatus::Dispatched, EventCode::PreparationStarted],
]);

it('never reopens a terminal order', function (OrderStatus $terminal, EventCode $code): void {
    expect(decide($terminal, $code))
        ->applies->toBeFalse()
        ->reason->toBe(Decision::ORDER_TERMINAL);
})->with([
    'CFM after CAN' => [OrderStatus::Cancelled, EventCode::Confirmed],
    'DSP after CAN' => [OrderStatus::Cancelled, EventCode::Dispatched],
    'CAN after CAN' => [OrderStatus::Cancelled, EventCode::Cancelled],
    'CAN after CON' => [OrderStatus::Concluded, EventCode::Cancelled],
    'CAR after CAN' => [OrderStatus::Cancelled, EventCode::CancellationRequested],
]);

it('treats CAR and CARF as informational', function (EventCode $code): void {
    $decision = decide(OrderStatus::Confirmed, $code);

    expect($decision->applies)->toBeFalse()
        ->and($decision->isInformational())->toBeTrue()
        ->and($decision->to)->toBeNull();
})->with([EventCode::CancellationRequested, EventCode::CancellationRequestFailed]);
