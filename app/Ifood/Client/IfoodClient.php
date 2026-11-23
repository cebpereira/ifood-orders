<?php

declare(strict_types=1);

namespace App\Ifood\Client;

use App\Ifood\Data\PolledEvent;
use App\Ifood\Exceptions\IfoodAuthException;
use App\Ifood\Exceptions\IfoodException;
use App\Ifood\Exceptions\IfoodNotFoundException;
use App\Ifood\Exceptions\IfoodRateLimitedException;
use App\Ifood\Exceptions\IfoodRequestException;
use App\Support\CorrelationId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Thin client for the iFood Order API. Paths and payloads follow what was
 * confirmed with real credentials (docs/discovery.md), not the public docs.
 *
 * Failure contract:
 * - 401: token is dropped and the call is retried once, then IfoodAuthException
 * - 404: IfoodNotFoundException
 * - 429: IfoodRateLimitedException, and every other call short-circuits until reset
 * - connection errors / 5xx: retried in place, then IfoodRequestException
 */
final class IfoodClient
{
    private const string POLLING = '/order/v1.0/events:polling';

    private const string ACK = '/order/v1.0/events/acknowledgment';

    private const string ORDERS = '/order/v1.0/orders';

    public function __construct(
        private readonly Http $http,
        private readonly TokenProvider $tokens,
        private readonly RateLimitGate $rateLimit,
        private readonly string $baseUrl,
        private readonly string $merchantId,
        private readonly int $timeout = 10,
        private readonly int $connectTimeout = 5,
        private readonly int $retries = 2,
        private readonly int $retrySleepMs = 300,
        private readonly int $ackChunkSize = 2000,
    ) {}

    /**
     * Events not yet acknowledged. iFood answers 204 when there is nothing.
     * A malformed event is logged and skipped: it is not acked, so it comes
     * back on the next poll instead of being lost.
     *
     * @return list<PolledEvent>
     */
    public function pollEvents(): array
    {
        $response = $this->send('GET', self::POLLING, [
            'headers' => ['x-polling-merchants' => $this->merchantId],
        ]);

        if ($response->status() === 204) {
            return [];
        }

        $events = [];
        foreach ((array) $response->json() as $item) {
            try {
                $events[] = PolledEvent::fromArray(is_array($item) ? $item : []);
            } catch (InvalidArgumentException $e) {
                Log::channel('ifood')->error('ifood.polling.malformed_event', [
                    'error' => $e->getMessage(),
                    'event_id' => is_array($item) ? ($item['id'] ?? null) : null,
                ]);
            }
        }

        return $events;
    }

    /**
     * Body is a list of {"id": ...} objects, not {"acknowledgedEventIds": [...]}
     * (which answers 400). At most 2000 ids per call.
     *
     * @param  list<string>  $eventIds
     */
    public function acknowledge(array $eventIds): void
    {
        foreach (array_chunk(array_values(array_unique($eventIds)), max(1, $this->ackChunkSize)) as $chunk) {
            $this->send('POST', self::ACK, [
                'json' => array_map(fn (string $id): array => ['id' => $id], $chunk),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function order(string $orderId): array
    {
        /** @var array<string, mixed> */
        return (array) $this->send('GET', $this->orderPath($orderId))->json();
    }

    /**
     * The list depends on the order type, so it is always fetched per order.
     *
     * @return list<array{code: string, description: string}>
     */
    public function cancellationReasons(string $orderId): array
    {
        $reasons = [];
        foreach ((array) $this->send('GET', $this->orderPath($orderId, 'cancellationReasons'))->json() as $reason) {
            if (is_array($reason) && is_scalar($reason['cancelCodeId'] ?? null)) {
                $reasons[] = [
                    'code' => (string) $reason['cancelCodeId'],
                    'description' => is_string($reason['description'] ?? null) ? $reason['description'] : '',
                ];
            }
        }

        return $reasons;
    }

    // Actions: all POST, all answer 202. The outcome arrives later as an event,
    // or not at all (see docs/discovery.md), so callers must track them.

    public function confirm(string $orderId): void
    {
        $this->action($orderId, 'confirm');
    }

    public function startPreparation(string $orderId): void
    {
        $this->action($orderId, 'startPreparation');
    }

    public function readyToPickup(string $orderId): void
    {
        $this->action($orderId, 'readyToPickup');
    }

    public function dispatch(string $orderId): void
    {
        $this->action($orderId, 'dispatch');
    }

    public function requestCancellation(string $orderId, string $cancellationCode, string $reason): void
    {
        $this->action($orderId, 'requestCancellation', [
            'reason' => $reason,
            'cancellationCode' => $cancellationCode,
        ]);
    }

    /** @param  array<string, string>  $body */
    private function action(string $orderId, string $action, array $body = []): void
    {
        $this->send('POST', $this->orderPath($orderId, $action), [
            // An empty JSON object is what was validated against the real API.
            'json' => $body === [] ? new stdClass : $body,
        ]);
    }

    private function orderPath(string $orderId, ?string $action = null): string
    {
        return self::ORDERS.'/'.rawurlencode($orderId).($action ? '/'.$action : '');
    }

    /** @param  array<string, mixed>  $options */
    private function send(string $method, string $path, array $options = [], bool $isAuthRetry = false): Response
    {
        $this->rateLimit->ensureOpen();

        $correlationId = CorrelationId::get();
        $started = hrtime(true);

        try {
            $response = $this->http->baseUrl($this->baseUrl)
                ->withToken($this->tokens->token())
                ->acceptJson()
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->retry(
                    $this->retries + 1,
                    fn (int $attempt): int => $this->retrySleepMs * $attempt,
                    fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->send($method, $path, $options);
        } catch (ConnectionException $e) {
            Log::channel('ifood')->error('ifood.request.unreachable', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new IfoodRequestException("iFood {$method} {$path} unreachable", previous: $e);
        }

        $status = $response->status();
        $level = $response->successful() ? 'info' : ($status === 404 ? 'notice' : 'warning');

        Log::channel('ifood')->log($level, 'ifood.request', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'ifood_request_id' => $response->header('x-request-id') ?: null,
            'error_code' => $response->successful() ? null : IfoodException::errorDetails($response)[0],
            'correlation_id' => $correlationId,
        ]);

        if ($response->successful()) {
            return $response;
        }

        if ($status === 401) {
            $this->tokens->forget();

            if (! $isAuthRetry) {
                return $this->send($method, $path, $options, isAuthRetry: true);
            }

            throw IfoodAuthException::fromResponse($method, $path, $response);
        }

        if ($status === 429) {
            throw IfoodRateLimitedException::until(
                $this->rateLimit->closeFrom($response),
                $response->header('x-request-id') ?: null,
            );
        }

        throw $this->exceptionFor($status, $method, $path, $response);
    }

    private function exceptionFor(int $status, string $method, string $path, Response $response): IfoodException
    {
        return $status === 404
            ? IfoodNotFoundException::fromResponse($method, $path, $response)
            : IfoodRequestException::fromResponse($method, $path, $response);
    }
}
