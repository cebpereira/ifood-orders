<?php

declare(strict_types=1);

namespace App\Ifood\Events;

use App\Ifood\Client\IfoodClient;
use App\Ifood\Data\PolledEvent;
use App\Ifood\Exceptions\IfoodException;
use App\Jobs\ProcessIfoodEventJob;
use App\Models\IfoodEvent;
use App\Support\CorrelationId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * One polling cycle: poll → persist → dispatch → acknowledge.
 *
 * - Persisting uses INSERT … ON CONFLICT DO NOTHING RETURNING, so the database
 *   decides what is new; duplicates (within a batch or redelivered) cost nothing.
 * - Every received id is acked, duplicates included: they are already stored.
 *   Nothing is acked unless the insert succeeded.
 * - A failed ack is not fatal: iFood redelivers, the insert ignores the
 *   duplicates, and the next cycle acks them again.
 */
final class EventIngestor
{
    public function __construct(private readonly IfoodClient $client) {}

    /**
     * @throws IfoodException when polling fails (the caller decides how loud to be)
     */
    public function poll(): IngestResult
    {
        $correlationId = CorrelationId::start();

        $events = $this->client->pollEvents();

        if ($events === []) {
            return new IngestResult;
        }

        $inserted = $this->persist($events, $correlationId);

        foreach ($inserted as $id) {
            ProcessIfoodEventJob::dispatch($id);
        }

        $acknowledged = $this->acknowledge($events);

        $result = new IngestResult(
            received: count($events),
            new: count($inserted),
            duplicates: count($events) - count($inserted),
            acknowledged: $acknowledged,
        );

        Log::channel('ifood')->info('ifood.polling.ingested', (array) $result);

        return $result;
    }

    /**
     * @param  list<PolledEvent>  $events
     * @return list<int> ids of the rows that did not exist yet, oldest event first
     */
    private function persist(array $events, string $correlationId): array
    {
        $now = now();

        $rows = array_map(fn (PolledEvent $e): array => [
            'event_id' => $e->id,
            'code' => $e->code,
            'full_code' => $e->fullCode,
            'order_id' => $e->orderId,
            'merchant_id' => $e->merchantId,
            'sales_channel' => $e->salesChannel,
            'event_created_at' => $e->createdAt->format('Y-m-d H:i:s.vP'),
            'metadata' => $e->metadata === [] ? null : $this->json($e->metadata),
            'payload' => $this->json($e->raw),
            'status' => 'received',
            'correlation_id' => $correlationId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $events);

        $inserted = DB::table('ifood_events')
            ->insertOrIgnoreReturning($rows, ['id', 'event_created_at'], 'event_id');

        $rows = [];
        foreach ($inserted as $row) {
            $id = data_get($row, 'id');
            $createdAt = data_get($row, 'event_created_at');

            if (is_numeric($id) && is_string($createdAt)) {
                $rows[(int) $id] = $createdAt; // UTC timestamps: string order is time order
            }
        }

        asort($rows);

        return array_keys($rows);
    }

    /** @param  list<PolledEvent>  $events */
    private function acknowledge(array $events): bool
    {
        $ids = array_values(array_unique(array_map(fn (PolledEvent $e): string => $e->id, $events)));

        try {
            $this->client->acknowledge($ids);
        } catch (IfoodException $e) {
            Log::channel('ifood')->warning('ifood.ack.failed', [
                'events' => count($ids),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        IfoodEvent::query()
            ->whereIn('event_id', $ids)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);

        return true;
    }

    /** @param  array<mixed>  $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return '{}';
        }
    }
}
