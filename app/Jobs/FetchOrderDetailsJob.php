<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ifood\Client\IfoodClient;
use App\Ifood\Exceptions\IfoodNotFoundException;
use App\Ifood\Exceptions\IfoodRateLimitedException;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches GET /orders/{id}. The public docs warn it may 404 right after PLC,
 * so a 404 is retried with exponential backoff for up to 10 minutes (it did
 * not happen during discovery, but nothing guarantees it will not).
 */
final class FetchOrderDetailsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** How long a 404 is treated as "not available yet". */
    public const int NOT_FOUND_WINDOW_MINUTES = 10;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $orderId) {}

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function retryUntil(): Carbon
    {
        return now()->addMinutes(self::NOT_FOUND_WINDOW_MINUTES + 1);
    }

    /** 5s, 10s, 20s, 40s, 80s, 160s, then every 5 minutes. */
    public static function notFoundBackoff(int $attempt): int
    {
        return min(5 * 2 ** max(0, $attempt - 1), 300);
    }

    public function handle(IfoodClient $client): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null || $order->details_fetched_at !== null) {
            return;
        }

        try {
            $details = $client->order($order->ifood_order_id);
        } catch (IfoodRateLimitedException $e) {
            $this->release($e->retryAfter);

            return;
        } catch (IfoodNotFoundException) {
            $this->handleNotFound($order);

            return;
        }

        $order->forceFill([
            'details' => $details,
            'details_fetched_at' => now(),
            'details_error' => null,
            'display_id' => self::str(data_get($details, 'displayId')),
            'order_type' => self::str(data_get($details, 'orderType')),
            'category' => self::str(data_get($details, 'category')),
            'sales_channel' => self::str(data_get($details, 'salesChannel')) ?? $order->sales_channel,
            'customer_name' => self::str(data_get($details, 'customer.name')),
            'total_amount' => is_numeric($total = data_get($details, 'total.orderAmount')) ? (string) $total : null,
            'is_test' => is_bool($test = data_get($details, 'isTest')) ? $test : null,
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        Order::query()->whereKey($this->orderId)->update([
            'details_error' => Str::limit((string) $e?->getMessage(), 250),
        ]);
    }

    private function handleNotFound(Order $order): void
    {
        $deadline = $order->created_at->addMinutes(self::NOT_FOUND_WINDOW_MINUTES);

        if (now()->greaterThanOrEqualTo($deadline)) {
            $order->forceFill(['details_error' => 'not_found_after_'.self::NOT_FOUND_WINDOW_MINUTES.'_minutes'])->save();

            Log::channel('ifood')->error('ifood.order.details_not_found', ['order_id' => $order->ifood_order_id]);

            return;
        }

        $delay = self::notFoundBackoff($this->attempts());

        Log::channel('ifood')->notice('ifood.order.details_pending', [
            'order_id' => $order->ifood_order_id,
            'attempt' => $this->attempts(),
            'retry_in' => $delay,
        ]);

        $this->release($delay);
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
