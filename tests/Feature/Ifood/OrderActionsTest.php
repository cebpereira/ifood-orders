<?php

declare(strict_types=1);

use App\Ifood\Actions\ActionNotAllowedException;
use App\Ifood\Actions\OrderActionService;
use App\Ifood\Enums\OrderActionStatus;
use App\Ifood\Enums\OrderActionType;
use App\Ifood\Enums\OrderStatus;
use App\Jobs\FetchOrderDetailsJob;
use App\Jobs\ProcessIfoodEventJob;
use App\Jobs\SendOrderActionJob;
use App\Models\Order;
use App\Models\OrderAction;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();
    Bus::fake([FetchOrderDetailsJob::class, SendOrderActionJob::class]);
});

function placedOrder(string $type = 'DELIVERY'): Order
{
    ProcessIfoodEventJob::dispatchSync(eventFor('PLC', now()->subMinute()->toDateTimeString('millisecond'))->id);
    $order = Order::sole();
    $order->forceFill(['order_type' => $type])->save();

    return $order;
}

function requestAction(Order $order, OrderActionType $type, ?string $code = null): OrderAction
{
    return app(OrderActionService::class)->request($order, $type, $code, $code ? 'Motivo' : null);
}

/** Run the send job as a worker would; optionally expect a release(). */
function sendAction(OrderAction $action, ?int $expectRelease = null): OrderAction
{
    $queueJob = Mockery::mock(Job::class);
    $expectRelease === null
        ? $queueJob->shouldNotReceive('release')
        : $queueJob->shouldReceive('release')->once()->with($expectRelease);

    $job = new SendOrderActionJob($action->id);
    $job->setJob($queueJob);
    app()->call([$job, 'handle']);

    return $action->refresh();
}

function fakeActionResponse(mixed $response): void
{
    Http::fake([
        ifoodUrl('/authentication/v1.0/oauth/token') => Http::response(ifoodFixture('token')),
        ifoodUrl('/order/v1.0/orders/*') => $response,
    ]);
}

describe('requesting', function (): void {
    it('records the action and queues the call to iFood', function (): void {
        $action = requestAction(placedOrder(), OrderActionType::Confirm);

        expect($action->status)->toBe(OrderActionStatus::Pending);
        Bus::assertDispatched(SendOrderActionJob::class, fn ($job) => $job->orderActionId === $action->id);
    });

    it('refuses actions that do not fit the order', function (): void {
        requestAction(placedOrder(), OrderActionType::Dispatch);
    })->throws(ActionNotAllowedException::class, 'Despachar');

    it('refuses a second action while one is waiting for iFood', function (): void {
        $order = placedOrder();
        requestAction($order, OrderActionType::Confirm);

        requestAction($order, OrderActionType::RequestCancellation, '501');
    })->throws(ActionNotAllowedException::class, 'Já existe uma ação');

    it('requires a reason to cancel', function (): void {
        requestAction(placedOrder(), OrderActionType::RequestCancellation);
    })->throws(ActionNotAllowedException::class, 'motivo');
});

describe('sending', function (): void {
    it('marks the action as sent on 202 and waits for the event', function (): void {
        $this->freezeSecond();
        fakeActionResponse(Http::response(null, 202));
        $action = requestAction(placedOrder(), OrderActionType::Confirm);

        $action = sendAction($action);

        expect($action->status)->toBe(OrderActionStatus::Sent)
            ->and($action->expires_at->equalTo(now()->addSeconds(OrderAction::CONFIRMATION_TIMEOUT_SECONDS)))->toBeTrue();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/'.TEST_ORDER_ID.'/confirm'));
    });

    it('sends the cancellation code', function (): void {
        fakeActionResponse(Http::response(null, 202));
        $action = requestAction(placedOrder(), OrderActionType::RequestCancellation, '503');

        sendAction($action);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/requestCancellation') && $r['cancellationCode'] === '503');
    });

    it('fails with the iFood message on a 4xx', function (): void {
        fakeActionResponse(Http::response(['code' => '400 BAD_REQUEST', 'message' => 'Order is already cancelled'], 400));
        $action = requestAction(placedOrder(), OrderActionType::Confirm);

        $action = sendAction($action);

        expect($action->status)->toBe(OrderActionStatus::Failed)
            ->and($action->error)->toBe('Order is already cancelled')
            ->and($action->response_status)->toBe(400);
    });

    it('explains in Portuguese when iFood does not know the order', function (): void {
        fakeActionResponse(Http::response(ifoodFixture('order-not-found'), 404));
        $action = requestAction(placedOrder(), OrderActionType::Confirm);

        expect(sendAction($action)->error)->toBe('O iFood não encontrou este pedido');
    });

    it('waits for the rate limit reset', function (): void {
        fakeActionResponse(Http::response(null, 429, ['X-RateLimit-Reset' => '12']));
        $action = requestAction(placedOrder(), OrderActionType::Confirm);

        expect(sendAction($action, expectRelease: 12)->status)->toBe(OrderActionStatus::Pending);
    });

    it('retries server errors and fails the action when the job gives up', function (): void {
        fakeActionResponse(Http::response(null, 503));
        $action = requestAction(placedOrder(), OrderActionType::Confirm);

        expect(fn () => sendAction($action))->toThrow(App\Ifood\Exceptions\IfoodRequestException::class);
        expect($action->refresh()->status)->toBe(OrderActionStatus::Pending);

        (new SendOrderActionJob($action->id))->failed(new RuntimeException('iFood unreachable'));

        expect($action->refresh())
            ->status->toBe(OrderActionStatus::Failed)
            ->error->toContain('iFood unreachable');
    });
});

describe('resolving by event', function (): void {
    function sentAction(Order $order, OrderActionType $type, ?string $code = null): OrderAction
    {
        $action = requestAction($order, $type, $code);
        $action->forceFill(['status' => OrderActionStatus::Sent, 'sent_at' => now(), 'expires_at' => now()->addMinutes(2)])->save();

        return $action;
    }

    it('confirms the action when the matching event arrives', function (): void {
        $order = placedOrder();
        $action = sentAction($order, OrderActionType::Confirm);
        $cfm = eventFor('CFM', now()->toDateTimeString('millisecond'));

        ProcessIfoodEventJob::dispatchSync($cfm->id);

        expect($action->refresh())
            ->status->toBe(OrderActionStatus::Confirmed)
            ->resolved_by_event_id->toBe($cfm->id);
    });

    it('confirms when the order jumps past the target status', function (): void {
        $order = placedOrder();
        ProcessIfoodEventJob::dispatchSync(eventFor('CFM', now()->toDateTimeString('millisecond'))->id);
        $action = sentAction($order->refresh(), OrderActionType::StartPreparation);

        ProcessIfoodEventJob::dispatchSync(eventFor('DSP', now()->addSecond()->toDateTimeString('millisecond'))->id);

        expect($action->refresh()->status)->toBe(OrderActionStatus::Confirmed);
    });

    it('keeps a cancellation open on CAR and confirms it on CAN', function (): void {
        $order = placedOrder();
        $action = sentAction($order, OrderActionType::RequestCancellation, '503');

        ProcessIfoodEventJob::dispatchSync(eventFor('CAR', now()->toDateTimeString('millisecond'), metadata: ['reason_code' => '503'])->id);
        expect($action->refresh()->status)->toBe(OrderActionStatus::Sent);

        ProcessIfoodEventJob::dispatchSync(eventFor('CAN', now()->addSecond()->toDateTimeString('millisecond'))->id);
        expect($action->refresh()->status)->toBe(OrderActionStatus::Confirmed);
    });

    it('fails the cancellation on CARF and leaves the order as it was', function (): void {
        $order = placedOrder();
        $action = sentAction($order, OrderActionType::RequestCancellation, '503');

        ProcessIfoodEventJob::dispatchSync(eventFor('CAR', now()->toDateTimeString('millisecond'))->id);
        ProcessIfoodEventJob::dispatchSync(eventFor('CARF', now()->addSecond()->toDateTimeString('millisecond'), metadata: ['details' => 'Pedido já saiu para entrega'])->id);

        expect($action->refresh())
            ->status->toBe(OrderActionStatus::Failed)
            ->error->toBe('Recusado pelo iFood: Pedido já saiu para entrega')
            ->and($order->refresh()->status)->toBe(OrderStatus::Placed);
    });

    it('fails a pending confirmation when iFood cancels the order first', function (): void {
        $order = placedOrder();
        $action = sentAction($order, OrderActionType::Confirm);

        ProcessIfoodEventJob::dispatchSync(eventFor('CAN', now()->toDateTimeString('millisecond'), metadata: ['CANCEL_ORIGIN' => 'SCHEDULER'])->id);

        expect($action->refresh())
            ->status->toBe(OrderActionStatus::Failed)
            ->error->toBe('O pedido foi cancelado antes da confirmação');
    });

    it('allows a new action once the previous one is settled', function (): void {
        $order = placedOrder();
        sentAction($order, OrderActionType::Confirm);
        ProcessIfoodEventJob::dispatchSync(eventFor('CFM', now()->toDateTimeString('millisecond'))->id);

        expect(requestAction($order->refresh(), OrderActionType::StartPreparation)->status)->toBe(OrderActionStatus::Pending);
    });
});

describe('expiry', function (): void {
    it('expires actions iFood accepted but never confirmed', function (): void {
        $order = placedOrder();
        $action = requestAction($order, OrderActionType::Confirm);
        $action->forceFill(['status' => OrderActionStatus::Sent, 'expires_at' => now()->subSecond()])->save();

        $this->artisan('ifood:expire-actions')->expectsOutputToContain('Expired 1')->assertSuccessful();

        expect($action->refresh()->status)->toBe(OrderActionStatus::Expired);
    });

    it('leaves actions still within the window', function (): void {
        $action = requestAction(placedOrder(), OrderActionType::Confirm);
        $action->forceFill(['status' => OrderActionStatus::Sent, 'expires_at' => now()->addMinute()])->save();

        $this->artisan('ifood:expire-actions')->assertSuccessful();

        expect($action->refresh()->status)->toBe(OrderActionStatus::Sent);
    });
});

describe('panel endpoints', function (): void {
    it('accepts an action from the panel', function (): void {
        $order = placedOrder();

        $this->from("/?order={$order->id}")
            ->post("/orders/{$order->id}/actions", ['action' => 'confirm'])
            ->assertRedirect("/?order={$order->id}")
            ->assertSessionHas('success');

        expect(OrderAction::sole()->action)->toBe(OrderActionType::Confirm);
    });

    it('explains why an action was refused', function (): void {
        $order = placedOrder();

        $this->from('/')->post("/orders/{$order->id}/actions", ['action' => 'dispatch'])
            ->assertSessionHas('error');

        $this->post("/orders/{$order->id}/actions", ['action' => 'explode'])->assertSessionHasErrors('action');
        expect(OrderAction::count())->toBe(0);
    });

    it('lists cancellation reasons from iFood', function (): void {
        fakeActionResponse(Http::response(ifoodFixture('cancellation-reasons')));
        $order = placedOrder();

        $this->getJson("/orders/{$order->id}/cancellation-reasons")
            ->assertOk()
            ->assertJsonPath('reasons.0.code', '501');
    });

    it('reports when iFood has no reasons for the order', function (): void {
        fakeActionResponse(Http::response(['code' => '400 BAD_REQUEST', 'message' => 'Order is already cancelled'], 400));
        $order = placedOrder();

        $this->getJson("/orders/{$order->id}/cancellation-reasons")
            ->assertStatus(422)
            ->assertJsonPath('message', 'O iFood não devolveu os motivos de cancelamento: Order is already cancelled');
    });

    it('shows the available and recent actions on the order', function (): void {
        $order = placedOrder();
        requestAction($order, OrderActionType::Confirm);

        $this->get("/?order={$order->id}")->assertInertia(fn ($page) => $page
            ->where('selected.actions.0.action', 'confirm')
            ->where('selected.actions.0.status', 'pending')
            ->where('selected.open_action.status', 'pending')
            ->has('selected.available_actions', 2));
    });
});
