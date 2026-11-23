<?php

declare(strict_types=1);

use App\Ifood\Enums\OrderActionType as A;
use App\Ifood\Enums\OrderStatus as S;

it('offers only the actions that make sense for the order', function (S $status, ?string $type, array $expected): void {
    $allowed = array_values(array_filter(A::cases(), fn (A $a) => $a->allowedFor($status, $type)));

    expect($allowed)->toBe($expected);
})->with([
    'new delivery' => [S::Placed, 'DELIVERY', [A::Confirm, A::RequestCancellation]],
    'confirmed delivery: prepare, skip to ready, or dispatch' => [S::Confirmed, 'DELIVERY', [A::StartPreparation, A::ReadyToPickup, A::Dispatch, A::RequestCancellation]],
    'preparing delivery' => [S::PreparationStarted, 'DELIVERY', [A::ReadyToPickup, A::Dispatch, A::RequestCancellation]],
    'ready delivery' => [S::ReadyToPickup, 'DELIVERY', [A::Dispatch, A::RequestCancellation]],
    'ready dine-in: nothing to dispatch' => [S::ReadyToPickup, 'DINE_IN', [A::RequestCancellation]],
    'dispatched' => [S::Dispatched, 'DELIVERY', [A::RequestCancellation]],
    'cancelled' => [S::Cancelled, 'DELIVERY', []],
    'concluded' => [S::Concluded, 'DELIVERY', []],
]);
