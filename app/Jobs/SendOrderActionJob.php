<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ifood\Client\IfoodClient;
use App\Ifood\Enums\OrderActionStatus;
use App\Ifood\Enums\OrderActionType;
use App\Ifood\Exceptions\IfoodAuthException;
use App\Ifood\Exceptions\IfoodException;
use App\Ifood\Exceptions\IfoodRateLimitedException;
use App\Models\OrderAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one merchant action to iFood. A 202 only means "received": the action
 * stays open until the matching event arrives or it expires.
 */
final class SendOrderActionJob implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $orderActionId)
    {
        $this->onQueue(ProcessIfoodEventJob::QUEUE);
    }

    /** Past the 8-minute confirmation window an order action is pointless. */
    public function retryUntil(): Carbon
    {
        return now()->addMinutes(8);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [2, 5, 15];
    }

    public function handle(IfoodClient $client): void
    {
        $action = OrderAction::query()->with('order')->find($this->orderActionId);

        if ($action === null || $action->status !== OrderActionStatus::Pending || $action->order === null) {
            return;
        }

        $orderId = $action->order->ifood_order_id;

        try {
            match ($action->action) {
                OrderActionType::Confirm => $client->confirm($orderId),
                OrderActionType::StartPreparation => $client->startPreparation($orderId),
                OrderActionType::ReadyToPickup => $client->readyToPickup($orderId),
                OrderActionType::Dispatch => $client->dispatch($orderId),
                OrderActionType::RequestCancellation => $client->requestCancellation(
                    $orderId,
                    (string) $action->cancellation_code,
                    $action->cancellation_reason ?: 'Cancelado pela loja',
                ),
            };
        } catch (IfoodRateLimitedException $e) {
            $this->release($e->retryAfter);

            return;
        } catch (IfoodException $e) {
            // 4xx is a final answer from iFood; anything else is retried by the queue.
            if (! $e instanceof IfoodAuthException && $e->status !== null && $e->status >= 400 && $e->status < 500) {
                $action->forceFill(['response_status' => $e->status])->save();
                $action->resolve(OrderActionStatus::Failed, error: $e->errorCode === 'OrderNotFound'
                    ? 'O iFood não encontrou este pedido'
                    : ($e->errorMessage ?? $e->errorCode ?? $e->getMessage()));

                return;
            }

            throw $e;
        }

        $action->forceFill([
            'status' => OrderActionStatus::Sent,
            'response_status' => 202,
            'sent_at' => now(),
            'expires_at' => now()->addSeconds(OrderAction::CONFIRMATION_TIMEOUT_SECONDS),
        ])->save();

        Log::channel('ifood')->info('ifood.action.sent', ['order_id' => $orderId, 'action' => $action->action->value]);
    }

    public function failed(?Throwable $e): void
    {
        $action = OrderAction::query()->find($this->orderActionId);

        if ($action !== null && $action->status->isOpen()) {
            $action->resolve(OrderActionStatus::Failed, error: 'Não foi possível falar com o iFood: '.($e?->getMessage() ?? 'erro desconhecido'));
        }
    }
}
