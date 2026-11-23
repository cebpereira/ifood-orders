<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ifood\Client\IfoodClient;
use App\Ifood\Client\RateLimitGate;
use App\Ifood\Client\TokenProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenProvider::class, fn (Application $app): TokenProvider => new TokenProvider(
            http: $app->make(Http::class),
            cache: $app->make(Cache::class),
            baseUrl: self::string('ifood.base_url'),
            clientId: self::string('ifood.client_id'),
            clientSecret: self::string('ifood.client_secret'),
            authFlow: self::string('ifood.auth_flow'),
            refreshMargin: self::int('ifood.token.refresh_margin'),
        ));

        $this->app->singleton(RateLimitGate::class, fn (Application $app): RateLimitGate => new RateLimitGate(
            cache: $app->make(Cache::class),
            defaultBackoff: self::int('ifood.rate_limit.default_backoff'),
        ));

        $this->app->singleton(IfoodClient::class, fn (Application $app): IfoodClient => new IfoodClient(
            http: $app->make(Http::class),
            tokens: $app->make(TokenProvider::class),
            rateLimit: $app->make(RateLimitGate::class),
            baseUrl: self::string('ifood.base_url'),
            merchantId: self::string('ifood.merchant_id'),
            timeout: self::int('ifood.http.timeout'),
            connectTimeout: self::int('ifood.http.connect_timeout'),
            retries: self::int('ifood.http.retries'),
            retrySleepMs: self::int('ifood.http.retry_sleep_ms'),
            ackChunkSize: self::int('ifood.ack_chunk_size'),
        ));
    }

    public function boot(): void
    {
        //
    }

    /** Missing credentials become '' so the app still boots; the token call then fails loudly. */
    private static function string(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }

    private static function int(string $key): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : 0;
    }
}
