<?php

declare(strict_types=1);

use App\Ifood\Enums\OrderStatus;
use App\Ifood\Events\IngestResult;
use App\Ifood\Events\PollingHealth;
use App\Jobs\FetchOrderDetailsJob;
use App\Jobs\ProcessIfoodEventJob;
use App\Models\Order;
use Database\Seeders\DemoOrdersSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Page;

it('responds on the health check endpoint', function (): void {
    $this->get('/up')->assertOk();
});

it('shows active orders and orders closed in the last 24 hours on the board', function (): void {
    Order::factory()->status(OrderStatus::Placed)->create(['display_id' => '1001']);
    Order::factory()->status(OrderStatus::Dispatched)->create(['display_id' => '1002']);
    Order::factory()->status(OrderStatus::Concluded)->create(['display_id' => '1003', 'status_changed_at' => now()->subHours(2)]);
    Order::factory()->status(OrderStatus::Cancelled)->create(['display_id' => '1004', 'status_changed_at' => now()->subDays(2)]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Page $page) => $page
            ->component('orders/index')
            ->where('view', 'board')
            ->has('orders.data', 3)
            ->where('counts.PLACED', 1)
            ->where('counts.CANCELLED', 1)
            ->where('selected', null));
});

it('exposes only what a ticket needs', function (): void {
    Order::factory()->create(['display_id' => '4374', 'total_amount' => 27, 'customer_name' => 'Cliente']);

    $this->get('/')->assertInertia(fn (Page $page) => $page
        ->has('orders.data.0', fn (Page $order) => $order
            ->where('display_id', '4374')
            ->where('status', 'PLACED')
            ->where('total_amount', 27)
            ->where('details_state', 'pending')
            ->hasAll(['id', 'ifood_order_id', 'customer_name', 'confirm_deadline_at', 'placed_at', 'order_type'])
            ->etc()));
});

it('lists every order with status filter and pagination', function (): void {
    Order::factory()->count(30)->status(OrderStatus::Concluded)->create(['status_changed_at' => now()->subDays(3)]);
    Order::factory()->count(2)->status(OrderStatus::Cancelled)->create();

    $this->get('/?view=list')->assertInertia(fn (Page $page) => $page
        ->where('view', 'list')
        ->has('orders.data', 25)
        ->where('orders.meta.total', 32)
        ->where('orders.meta.last_page', 2));

    $this->get('/?view=list&status=CANCELLED')->assertInertia(fn (Page $page) => $page
        ->has('orders.data', 2)
        ->where('filters.status', 'CANCELLED'));
});

it('searches by order number or customer', function (): void {
    Order::factory()->create(['display_id' => '4374', 'customer_name' => 'Ana']);
    Order::factory()->create(['display_id' => '9999', 'customer_name' => 'Bruno']);

    $this->get('/?q=437')->assertInertia(fn (Page $page) => $page->has('orders.data', 1)->where('orders.data.0.display_id', '4374'));
    $this->get('/?q=brun')->assertInertia(fn (Page $page) => $page->has('orders.data', 1)->where('orders.data.0.customer_name', 'Bruno'));
    $this->get('/?q=100%25')->assertOk();
});

it('opens an order with its full event timeline, ignored events included', function (): void {
    Bus::fake([FetchOrderDetailsJob::class]);
    foreach ([
        eventFor('PLC', '2026-11-23 18:00:00'),
        eventFor('CAN', '2026-11-23 18:08:00', metadata: ['CANCEL_ORIGIN' => 'SCHEDULER', 'CANCEL_CODE' => '902']),
        eventFor('CFM', '2026-11-23 18:07:59'),
    ] as $event) {
        ProcessIfoodEventJob::dispatchSync($event->id);
    }
    $order = Order::sole();

    $this->get("/?order={$order->id}")->assertInertia(fn (Page $page) => $page
        ->where('selected.status', 'CANCELLED')
        ->where('selected.cancellation_origin', 'SCHEDULER')
        ->has('selected.timeline', 3)
        ->where('selected.timeline.1.code', 'CFM')
        ->where('selected.timeline.1.status', 'ignored')
        ->where('selected.timeline.1.ignore_reason', 'order_terminal')
        ->where('selected.timeline.2.code', 'CAN')
        ->where('selected.timeline.2.applied', true));
});

it('keeps customer contact data out of the panel', function (): void {
    $order = Order::factory()->create([
        'details' => ['customer' => ['phone' => ['number' => '11999999999'], 'documentNumber' => '12345678900'], 'delivery' => ['deliveryAddress' => ['streetName' => 'Rua X']]],
        'details_fetched_at' => now(),
    ]);

    $this->get("/?order={$order->id}")
        ->assertOk()
        ->assertDontSee('11999999999')
        ->assertDontSee('12345678900')
        ->assertDontSee('Rua X');
});

it('reports the last polling cycle', function (): void {
    app(PollingHealth::class)->recordSuccess(new IngestResult(received: 3, new: 3));

    $this->get('/')->assertInertia(fn (Page $page) => $page
        ->where('health.polling.state', 'ok')
        ->where('health.polling.received', 3)
        ->where('health.events_failed', 0));
});

it('records polling problems for the panel', function (): void {
    Http::fake([
        ifoodUrl('/authentication/v1.0/oauth/token') => Http::response(ifoodFixture('token')),
        ifoodUrl('/order/v1.0/events:polling') => Http::response(null, 429, ['X-RateLimit-Reset' => '20']),
    ]);

    $this->artisan('ifood:poll')->assertSuccessful();

    expect(app(PollingHealth::class)->last())->toMatchArray(['state' => 'rate_limited']);
});

it('seeds demo orders through the real processing pipeline', function (): void {
    $this->seed(DemoOrdersSeeder::class);

    expect(Order::count())->toBe(9)
        ->and(Order::where('status', OrderStatus::Placed)->count())->toBe(2)
        ->and(Order::where('customer_name', 'Fábio Nunes')->sole()->status)->toBe(OrderStatus::Dispatched)
        ->and(Order::where('customer_name', 'Heitor Campos')->sole())
        ->status->toBe(OrderStatus::Cancelled)
        ->cancellation_origin->toBe('SCHEDULER');
    // The seeder fakes the details job: it is recorded, never run against iFood.
    Bus::assertDispatched(FetchOrderDetailsJob::class);
});
