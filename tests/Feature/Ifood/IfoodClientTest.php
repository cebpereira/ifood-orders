<?php

declare(strict_types=1);

use App\Ifood\Client\IfoodClient;
use App\Ifood\Client\RateLimitGate;
use App\Ifood\Client\TokenProvider;
use App\Ifood\Data\PolledEvent;
use App\Ifood\Exceptions\IfoodAuthException;
use App\Ifood\Exceptions\IfoodNotFoundException;
use App\Ifood\Exceptions\IfoodRateLimitedException;
use App\Ifood\Exceptions\IfoodRequestException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const ORDER_ID = '0a000000-0000-4000-8000-0000000000aa';

beforeEach(function (): void {
    Sleep::fake();
});

function client(): IfoodClient
{
    return app(IfoodClient::class);
}

/** Fake the token endpoint plus the given routes. */
function fakeIfood(array $routes): void
{
    Http::fake([
        ifoodUrl('/authentication/v1.0/oauth/token') => Http::response(ifoodFixture('token')),
        ...$routes,
    ]);
}

function tokenRequests(): int
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), '/oauth/token'))->count();
}

describe('authentication', function (): void {
    it('requests a token with camelCase form fields and caches it across calls', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/events:polling') => Http::response(null, 204)]);

        client()->pollEvents();
        client()->pollEvents();

        expect(tokenRequests())->toBe(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/oauth/token')
            && $r['grantType'] === 'client_credentials'
            && $r['clientId'] === 'test-client-id'
            && $r['clientSecret'] === 'test-client-secret');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'events:polling')
            && $r->hasHeader('Authorization', 'Bearer fake-access-token-1'));
    });

    it('requests a new token once the cached one is about to expire', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/events:polling') => Http::response(null, 204)]);

        client()->pollEvents();
        $this->travel(21599 - 300 + 1)->seconds();
        client()->pollEvents();

        expect(tokenRequests())->toBe(2);
    });

    it('drops the token and retries once on 401', function (): void {
        Http::fake([
            ifoodUrl('/authentication/v1.0/oauth/token') => Http::sequence()
                ->push(['accessToken' => 'stale', 'expiresIn' => 21599])
                ->push(['accessToken' => 'fresh', 'expiresIn' => 21599]),
            ifoodUrl('/order/v1.0/events:polling') => Http::sequence()
                ->push(null, 401)
                ->push(null, 204),
        ]);

        expect(client()->pollEvents())->toBe([])
            ->and(tokenRequests())->toBe(2)
            ->and(Cache::get(TokenProvider::CACHE_KEY))->toBe('fresh');
    });

    it('gives up after a second 401', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/events:polling') => Http::response(null, 401)]);

        expect(fn () => client()->pollEvents())->toThrow(IfoodAuthException::class);
    });

    it('fails loudly when the credentials are rejected', function (): void {
        Http::fake([ifoodUrl('/authentication/v1.0/oauth/token') => Http::response(['error' => ['code' => 'Unauthorized']], 401)]);

        expect(fn () => client()->pollEvents())->toThrow(IfoodAuthException::class);
    });
});

describe('polling', function (): void {
    it('returns no events on 204', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/events:polling') => Http::response(null, 204)]);

        expect(client()->pollEvents())->toBe([]);
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-polling-merchants', '00000000-0000-0000-0000-000000000001'));
    });

    it('maps events, keeping unknown codes and optional metadata', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/events:polling') => Http::response(ifoodFixture('polling'))]);

        $events = client()->pollEvents();

        expect($events)->toHaveCount(3)->each->toBeInstanceOf(PolledEvent::class)
            ->and($events[0]->code)->toBe('PLC')
            ->and($events[0]->metadata)->toBe([])
            ->and($events[0]->createdAt->toIso8601ZuluString('millisecond'))->toBe('2026-11-23T18:12:01.307Z')
            ->and($events[1]->metadata)->toMatchArray(['ORIGIN' => 'ORDER_API'])
            ->and($events[2]->code)->toBe('DDCR');
    });

    it('skips a malformed event instead of failing the whole batch', function (): void {
        $payload = ifoodFixture('polling');
        unset($payload[1]['orderId']);
        fakeIfood([ifoodUrl('/order/v1.0/events:polling') => Http::response($payload)]);

        expect(client()->pollEvents())->toHaveCount(2);
    });
});

describe('acknowledgment', function (): void {
    it('sends a list of id objects to the events endpoint', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/events/acknowledgment') => Http::response(null, 202)]);

        client()->acknowledge(['a', 'b', 'a']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/order/v1.0/events/acknowledgment')
            && $r->method() === 'POST'
            && $r->data() === [['id' => 'a'], ['id' => 'b']]);
    });

    it('splits large batches into chunks of 2000', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/events/acknowledgment') => Http::response(null, 202)]);

        client()->acknowledge(array_map(fn (int $i): string => "event-{$i}", range(1, 4500)));

        $sizes = Http::recorded(fn (Request $r) => str_contains($r->url(), 'acknowledgment'))
            ->map(fn (array $pair) => count($pair[0]->data()))
            ->values()
            ->all();

        expect($sizes)->toBe([2000, 2000, 500]);
    });

    it('does not call iFood with nothing to acknowledge', function (): void {
        fakeIfood([]);

        client()->acknowledge([]);

        Http::assertNothingSent();
    });
});

describe('orders and actions', function (): void {
    it('throws a not-found exception carrying the iFood error code', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/orders/*') => Http::response(ifoodFixture('order-not-found'), 404, ['x-request-id' => 'req-1'])]);

        try {
            client()->order(ORDER_ID);
            $this->fail('Expected IfoodNotFoundException');
        } catch (IfoodNotFoundException $e) {
            expect($e->status)->toBe(404)
                ->and($e->errorCode)->toBe('OrderNotFound')
                ->and($e->ifoodRequestId)->toBe('req-1');
        }
    });

    it('normalizes cancellation reason codes to strings', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/orders/*/cancellationReasons') => Http::response(ifoodFixture('cancellation-reasons'))]);

        expect(client()->cancellationReasons(ORDER_ID))->toBe([
            ['code' => '501', 'description' => 'Problemas de sistema na loja'],
            ['code' => '503', 'description' => 'Item indisponível/desatualizado'],
        ]);
    });

    it('sends every action as POST', function (string $method, string $path): void {
        fakeIfood([ifoodUrl('/order/v1.0/orders/*') => Http::response(null, 202)]);

        client()->{$method}(ORDER_ID);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/order/v1.0/orders/'.ORDER_ID.'/'.$path));
    })->with([
        ['confirm', 'confirm'],
        ['startPreparation', 'startPreparation'],
        ['readyToPickup', 'readyToPickup'],
        ['dispatch', 'dispatch'],
    ]);

    it('sends cancellationCode, which iFood requires, as a string', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/orders/*') => Http::response(null, 202)]);

        client()->requestCancellation(ORDER_ID, '503', 'Item indisponível');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/requestCancellation')
            && $r->data() === ['reason' => 'Item indisponível', 'cancellationCode' => '503']);
    });

    it('understands the root-level error shape', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/orders/*') => Http::response([
            'code' => '400 BAD_REQUEST',
            'message' => 'Order X is already cancelled',
        ], 400)]);

        try {
            client()->cancellationReasons(ORDER_ID);
            $this->fail('Expected IfoodRequestException');
        } catch (IfoodRequestException $e) {
            expect($e->errorCode)->toBe('400 BAD_REQUEST')
                ->and($e->errorMessage)->toBe('Order X is already cancelled')
                ->and($e->getMessage())->toContain('already cancelled');
        }
    });

    it('surfaces validation errors', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/orders/*') => Http::response(['error' => ['code' => 'InvalidParameter']], 400)]);

        expect(fn () => client()->confirm(ORDER_ID))->toThrow(IfoodRequestException::class);
    });
});

describe('transient failures', function (): void {
    it('retries 5xx in place and succeeds', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/events:polling') => Http::sequence()
            ->push(null, 503)
            ->push(null, 502)
            ->push(null, 204)]);

        expect(client()->pollEvents())->toBe([]);
    });

    it('gives up after the configured retries', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/events:polling') => Http::response(null, 503)]);

        expect(fn () => client()->pollEvents())->toThrow(IfoodRequestException::class);
        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'polling'))->count())->toBe(3);
    });

    it('does not retry 4xx', function (): void {
        fakeIfood([ifoodUrl('/order/v1.0/orders/*') => Http::response(null, 404)]);

        expect(fn () => client()->order(ORDER_ID))->toThrow(IfoodNotFoundException::class);
        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/orders/'))->count())->toBe(1);
    });
});

describe('rate limiting', function (): void {
    it('reads the wait time from X-RateLimit-Reset', function (string $reset, int $expected): void {
        $this->freezeTime();
        $reset = str_replace('{now+40}', (string) (now()->getTimestamp() + 40), $reset);
        fakeIfood([ifoodUrl('/order/v1.0/events:polling') => Http::response(null, 429, [
            'X-RateLimit-Limit' => '100',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => $reset,
        ])]);

        try {
            client()->pollEvents();
            $this->fail('Expected IfoodRateLimitedException');
        } catch (IfoodRateLimitedException $e) {
            expect($e->retryAfter)->toBe($expected);
        }
    })->with([
        'seconds until reset' => ['25', 25],
        'unix timestamp' => ['{now+40}', 40],
        'unusable value' => ['soon', 60],
    ]);

    it('stops calling iFood until the limit resets', function (): void {
        $this->freezeTime();
        fakeIfood([ifoodUrl('/order/v1.0/*') => Http::sequence()
            ->push(null, 429, ['X-RateLimit-Reset' => '30'])
            ->push(null, 204)]);

        expect(fn () => client()->pollEvents())->toThrow(IfoodRateLimitedException::class);
        expect(fn () => client()->order(ORDER_ID))->toThrow(IfoodRateLimitedException::class);
        expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/order/'))->count())->toBe(1);

        $this->travel(31)->seconds();

        expect(client()->pollEvents())->toBe([])
            ->and(Cache::has(RateLimitGate::CACHE_KEY))->toBeFalse();
    });
});

describe('logging', function (): void {
    it('logs every call with the correlation id and iFood request id, never secrets', function (): void {
        $records = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$records): void {
            $records[] = $e;
        });
        Context::add('correlation_id', 'corr-123');
        fakeIfood([ifoodUrl('/order/v1.0/events:polling') => Http::response(null, 204, ['x-request-id' => 'ifood-req-9'])]);

        client()->pollEvents();

        $request = collect($records)->firstWhere('message', 'ifood.request');
        expect($request)->not->toBeNull()
            ->and($request->context)->toMatchArray([
                'method' => 'GET',
                'path' => '/order/v1.0/events:polling',
                'status' => 204,
                'ifood_request_id' => 'ifood-req-9',
                'correlation_id' => 'corr-123',
            ]);

        $everything = json_encode(array_map(fn (MessageLogged $e) => [$e->message, $e->context], $records));
        expect($everything)
            ->not->toContain('test-client-secret')
            ->not->toContain('test-client-id')
            ->not->toContain('fake-access-token-1');
    });
});
