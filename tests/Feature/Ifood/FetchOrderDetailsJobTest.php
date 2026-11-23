<?php

declare(strict_types=1);

use App\Jobs\FetchOrderDetailsJob;
use App\Models\Order;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

function fakeOrderDetails(mixed $response): void
{
    Http::fake([
        ifoodUrl('/authentication/v1.0/oauth/token') => Http::response(ifoodFixture('token')),
        ifoodUrl('/order/v1.0/orders/*') => $response,
    ]);
}

/** Run the job as a worker would, with a mocked queue job to observe release(). */
function runDetailsJob(Order $order, int $attempt = 1, ?int $expectRelease = null): void
{
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $expectRelease === null
        ? $queueJob->shouldNotReceive('release')
        : $queueJob->shouldReceive('release')->once()->with($expectRelease);

    $job = new FetchOrderDetailsJob($order->id);
    $job->setJob($queueJob);
    app()->call([$job, 'handle']);
}

function pendingOrder(): Order
{
    return Order::factory()->create([
        'ifood_order_id' => TEST_ORDER_ID,
        'display_id' => null,
        'customer_name' => null,
        'total_amount' => null,
        'order_type' => null,
    ]);
}

it('stores the details and the fields the panel shows', function (): void {
    fakeOrderDetails(Http::response(ifoodFixture('order-details')));
    $order = pendingOrder();

    runDetailsJob($order);

    expect($order->refresh())
        ->display_id->toBe('4374')
        ->order_type->toBe('DELIVERY')
        ->customer_name->toBe('Cliente de teste')
        ->total_amount->toBe('27.00')
        ->is_test->toBeTrue()
        ->details_fetched_at->not->toBeNull()
        ->and($order->details['items'][0]['name'])->toBe('Produto de teste');
});

it('retries a 404 with exponential backoff', function (int $attempt, int $delay): void {
    fakeOrderDetails(Http::response(ifoodFixture('order-not-found'), 404));
    $order = pendingOrder();

    runDetailsJob($order, attempt: $attempt, expectRelease: $delay);

    expect($order->refresh()->details_fetched_at)->toBeNull();
})->with([
    [1, 5],
    [2, 10],
    [3, 20],
    [6, 160],
    [9, 300],
]);

it('succeeds once the order becomes available', function (): void {
    fakeOrderDetails(Http::sequence()
        ->push(ifoodFixture('order-not-found'), 404)
        ->push(ifoodFixture('order-details')));
    $order = pendingOrder();

    runDetailsJob($order, attempt: 1, expectRelease: 5);
    runDetailsJob($order, attempt: 2);

    expect($order->refresh()->display_id)->toBe('4374');
});

it('gives up on 404 after 10 minutes', function (): void {
    fakeOrderDetails(Http::response(ifoodFixture('order-not-found'), 404));
    $order = pendingOrder();
    $this->travel(10)->minutes();

    runDetailsJob($order, attempt: 12);

    expect($order->refresh())
        ->details_fetched_at->toBeNull()
        ->details_error->toBe('not_found_after_10_minutes');
});

it('waits for the rate limit reset', function (): void {
    fakeOrderDetails(Http::response(null, 429, ['X-RateLimit-Reset' => '15']));

    runDetailsJob(pendingOrder(), expectRelease: 15);
});

it('does not fetch twice', function (): void {
    fakeOrderDetails(Http::response(ifoodFixture('order-details')));
    $order = pendingOrder();
    $order->forceFill(['details_fetched_at' => now()])->save();

    runDetailsJob($order);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/orders/'));
});

it('records the error when the job finally fails', function (): void {
    $order = pendingOrder();

    (new FetchOrderDetailsJob($order->id))->failed(new RuntimeException('iFood unreachable'));

    expect($order->refresh()->details_error)->toBe('iFood unreachable');
});

it('is unique per order', function (): void {
    expect((new FetchOrderDetailsJob(42))->uniqueId())->toBe('42');
});
