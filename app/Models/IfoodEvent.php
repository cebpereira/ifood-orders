<?php

declare(strict_types=1);

namespace App\Models;

use App\Ifood\Enums\EventCode;
use App\Ifood\Enums\IfoodEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * A raw event received through polling. Never updated by iFood data after
 * insert: only the processing columns change.
 *
 * @property int $id
 * @property string $event_id
 * @property string $code
 * @property string $full_code
 * @property string $order_id
 * @property string $merchant_id
 * @property string|null $sales_channel
 * @property CarbonImmutable $event_created_at
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed> $payload
 * @property IfoodEventStatus $status
 * @property string|null $ignore_reason
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $next_retry_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $processed_at
 * @property string|null $correlation_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class IfoodEvent extends Model
{
    /**
     * Total failed attempts (across job runs and ifood:retry sweeps) after which
     * the event stays failed until someone retries it by hand.
     */
    public const int MAX_ATTEMPTS = 12;

    /** Keep milliseconds: iFood events can be ~150ms apart. */
    protected $dateFormat = 'Y-m-d H:i:s.vP';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'received',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'event_created_at' => 'immutable_datetime',
            'metadata' => 'array',
            'payload' => 'array',
            'status' => IfoodEventStatus::class,
            'attempts' => 'integer',
            'next_retry_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Null for codes this project does not handle (e.g. DDCR). */
    public function eventCode(): ?EventCode
    {
        return EventCode::tryFrom($this->code);
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'status' => IfoodEventStatus::Processed,
            'ignore_reason' => null,
            'last_error' => null,
            'next_retry_at' => null,
            'processed_at' => now(),
        ])->save();
    }

    public function markIgnored(string $reason): void
    {
        $this->forceFill([
            'status' => IfoodEventStatus::Ignored,
            'ignore_reason' => $reason,
            'last_error' => null,
            'next_retry_at' => null,
            'processed_at' => now(),
        ])->save();
    }

    /** One job attempt failed; the job itself may still retry. */
    public function recordFailedAttempt(Throwable $e): void
    {
        $this->forceFill([
            'attempts' => $this->attempts + 1,
            'last_error' => Str::limit($e::class.': '.$e->getMessage(), 2000),
        ])->save();
    }

    /** The job gave up. Schedule the next sweep with exponential backoff (2, 4, 8… min, max 1h). */
    public function markFailed(int $attemptsPerRun): void
    {
        $runs = max(1, intdiv($this->attempts, max(1, $attemptsPerRun)));

        $this->forceFill([
            'status' => IfoodEventStatus::Failed,
            'next_retry_at' => now()->addMinutes(min(2 ** $runs, 60)),
        ])->save();
    }

    /**
     * Failed events whose backoff has elapsed, plus received events nobody
     * picked up (e.g. a worker died after the insert, before the dispatch).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueForRetry(Builder $query, int $staleAfterSeconds = 120): Builder
    {
        return $query->where(function (Builder $q) use ($staleAfterSeconds): void {
            $q->where(function (Builder $failed): void {
                $failed->where('status', IfoodEventStatus::Failed)
                    ->where('attempts', '<', self::MAX_ATTEMPTS)
                    ->where('next_retry_at', '<=', now());
            })->orWhere(function (Builder $stale) use ($staleAfterSeconds): void {
                $stale->where('status', IfoodEventStatus::Received)
                    ->where('updated_at', '<=', now()->subSeconds($staleAfterSeconds));
            });
        })->orderBy('event_created_at');
    }
}
