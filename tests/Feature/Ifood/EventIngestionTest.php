<?php

declare(strict_types=1);

use App\Ifood\Enums\IfoodEventStatus;
use App\Jobs\ProcessIfoodEventJob;
use App\Models\IfoodEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();
    Queue::fake();
});

/** @param  array<mixed>|null  $events  null = 204 */
function fakePolling(?array ...$responses): void
{
    $sequence = Http::sequence();
    foreach ($responses as $events) {
        $events === null ? $sequence->push(null, 204) : $sequence->push($events);
    }

    Http::fake([
        ifoodUrl('/authentication/v1.0/oauth/token') => Http::response(ifoodFixture('token')),
        ifoodUrl('/order/v1.0/events:polling') => $sequence,
        ifoodUrl('/order/v1.0/events/acknowledgment') => Http::response(null, 202),
    ]);
}

/** @return list<string> */
function ackedIds(): array
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'acknowledgment'))
        ->flatMap(fn (array $pair) => array_column($pair[0]->data(), 'id'))
        ->values()
        ->all();
}

it('stores new events, dispatches one job per event and acks them', function (): void {
    fakePolling(ifoodFixture('polling'));

    $this->artisan('ifood:poll')
        ->expectsOutputToContain('received=3 new=3 duplicates=0 acked=yes')
        ->assertSuccessful();

    expect(IfoodEvent::count())->toBe(3)
        ->and(IfoodEvent::whereNull('acknowledged_at')->count())->toBe(0)
        ->and(IfoodEvent::where('status', '!=', 'received')->count())->toBe(0)
        ->and(ackedIds())->toEqualCanonicalizing(array_column(ifoodFixture('polling'), 'id'));

    Queue::assertPushedOn('ifood-events', ProcessIfoodEventJob::class);
    Queue::assertPushed(ProcessIfoodEventJob::class, 3);
});

it('keeps the raw payload and event metadata', function (): void {
    fakePolling(ifoodFixture('polling'));

    $this->artisan('ifood:poll')->assertSuccessful();

    $cfm = IfoodEvent::where('code', 'CFM')->sole();
    expect($cfm->metadata)->toMatchArray(['ORIGIN' => 'ORDER_API'])
        ->and($cfm->payload['fullCode'])->toBe('CONFIRMED')
        ->and($cfm->event_created_at->format('H:i:s.v'))->toBe('18:17:39.982')
        ->and($cfm->correlation_id)->not->toBeNull();
});

it('dispatches jobs oldest event first, whatever order iFood sends them in', function (): void {
    fakePolling(array_reverse(ifoodFixture('polling')));

    $this->artisan('ifood:poll')->assertSuccessful();

    $dispatchedCodes = Queue::pushed(ProcessIfoodEventJob::class)
        ->map(fn (ProcessIfoodEventJob $job) => IfoodEvent::find($job->ifoodEventId)?->code)
        ->values()
        ->all();

    expect($dispatchedCodes)->toBe(['PLC', 'CFM', 'DDCR']);
});

it('stores a redelivered event once but acks it again', function (): void {
    $events = ifoodFixture('polling');
    fakePolling($events, $events);

    $this->artisan('ifood:poll')->assertSuccessful();
    $this->artisan('ifood:poll')
        ->expectsOutputToContain('received=3 new=0 duplicates=3 acked=yes')
        ->assertSuccessful();

    expect(IfoodEvent::count())->toBe(3)
        ->and(ackedIds())->toHaveCount(6);
    Queue::assertPushed(ProcessIfoodEventJob::class, 3);
});

it('handles the same event twice in one batch', function (): void {
    $events = ifoodFixture('polling');
    fakePolling([...$events, $events[0]]);

    $this->artisan('ifood:poll')
        ->expectsOutputToContain('received=4 new=3 duplicates=1')
        ->assertSuccessful();

    expect(IfoodEvent::count())->toBe(3)
        ->and(ackedIds())->toHaveCount(3);
    Queue::assertPushed(ProcessIfoodEventJob::class, 3);
});

it('does nothing when iFood has no events', function (): void {
    fakePolling(null);

    $this->artisan('ifood:poll')->assertSuccessful();

    expect(IfoodEvent::count())->toBe(0);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'acknowledgment'));
    Queue::assertNothingPushed();
});

it('never acks events it could not store', function (): void {
    fakePolling(ifoodFixture('polling'));
    DB::statement('DROP TABLE ifood_events CASCADE');

    expect(fn () => $this->artisan('ifood:poll'))->toThrow(Exception::class);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'acknowledgment'));
    Queue::assertNothingPushed();
});

it('keeps the events when the ack fails, and acks them on the next cycle', function (): void {
    $events = ifoodFixture('polling');
    Http::fake([
        ifoodUrl('/authentication/v1.0/oauth/token') => Http::response(ifoodFixture('token')),
        ifoodUrl('/order/v1.0/events:polling') => Http::response($events),
        ifoodUrl('/order/v1.0/events/acknowledgment') => Http::sequence()
            ->push(null, 500)->push(null, 500)->push(null, 500) // first cycle, with in-place retries
            ->push(null, 202),
    ]);

    $this->artisan('ifood:poll')
        ->expectsOutputToContain('new=3 duplicates=0 acked=no')
        ->assertSuccessful();

    expect(IfoodEvent::count())->toBe(3)
        ->and(IfoodEvent::whereNull('acknowledged_at')->count())->toBe(3);
    Queue::assertPushed(ProcessIfoodEventJob::class, 3);

    $this->artisan('ifood:poll')
        ->expectsOutputToContain('new=0 duplicates=3 acked=yes')
        ->assertSuccessful();

    expect(IfoodEvent::whereNull('acknowledged_at')->count())->toBe(0);
    Queue::assertPushed(ProcessIfoodEventJob::class, 3);
});

it('skips the cycle quietly when rate limited', function (): void {
    Http::fake([
        ifoodUrl('/authentication/v1.0/oauth/token') => Http::response(ifoodFixture('token')),
        ifoodUrl('/order/v1.0/events:polling') => Http::response(null, 429, ['X-RateLimit-Reset' => '20']),
    ]);

    $this->artisan('ifood:poll')
        ->expectsOutputToContain('Rate limited')
        ->assertSuccessful();
});

it('fails the command when polling is rejected', function (): void {
    Http::fake([
        ifoodUrl('/authentication/v1.0/oauth/token') => Http::response(ifoodFixture('token')),
        ifoodUrl('/order/v1.0/events:polling') => Http::response(['code' => '400 BAD_REQUEST', 'message' => 'Invalid value for header: x-polling-merchants'], 400),
    ]);

    $this->artisan('ifood:poll')
        ->expectsOutputToContain('x-polling-merchants')
        ->assertFailed();
});

it('polls every 30 seconds without overlapping', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('30s  php artisan ifood:poll')
        ->assertSuccessful();
});

it('starts every received event as pending', function (): void {
    fakePolling(ifoodFixture('polling'));

    $this->artisan('ifood:poll')->assertSuccessful();

    expect(IfoodEvent::pluck('status')->unique()->all())->toBe([IfoodEventStatus::Received]);
});
