<?php

declare(strict_types=1);

namespace App\Ifood\Client;

use App\Ifood\Exceptions\IfoodAuthException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use SensitiveParameter;

/**
 * Centralized-flow (client_credentials) access token, cached until shortly
 * before it expires. A lock keeps concurrent workers from all requesting a new
 * token at the same time when it expires.
 */
final class TokenProvider
{
    public const string CACHE_KEY = 'ifood:access-token';

    private const string TOKEN_PATH = '/authentication/v1.0/oauth/token';

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly string $baseUrl,
        private readonly string $clientId,
        #[SensitiveParameter] private readonly string $clientSecret,
        private readonly string $authFlow,
        private readonly int $refreshMargin,
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_string($cached)) {
            return $cached;
        }

        $refresh = function (): string {
            // Another worker may have refreshed it while we waited for the lock.
            $cached = $this->cache->get(self::CACHE_KEY);

            return is_string($cached) ? $cached : $this->requestNewToken();
        };

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $refresh();
        }

        $token = $store->lock(self::CACHE_KEY.':lock', 10)->block(10, $refresh);

        return is_string($token) ? $token : $refresh();
    }

    /** Drop the cached token, e.g. after a 401. */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    private function requestNewToken(): string
    {
        if ($this->authFlow !== 'centralized') {
            throw new LogicException("iFood auth flow [{$this->authFlow}] is not implemented, only [centralized].");
        }

        try {
            $response = $this->http->baseUrl($this->baseUrl)
                ->asForm()
                ->acceptJson()
                ->post(self::TOKEN_PATH, [
                    // camelCase, confirmed in docs/discovery.md
                    'grantType' => 'client_credentials',
                    'clientId' => $this->clientId,
                    'clientSecret' => $this->clientSecret,
                ]);
        } catch (ConnectionException $e) {
            throw new IfoodAuthException('Could not reach iFood to get a token', previous: $e);
        }

        $token = $response->json('accessToken');
        $expiresIn = $response->json('expiresIn');

        if (! $response->successful() || ! is_string($token) || ! is_int($expiresIn)) {
            Log::channel('ifood')->error('ifood.token.failed', [
                'status' => $response->status(),
                'ifood_request_id' => $response->header('x-request-id'),
            ]);

            throw IfoodAuthException::fromResponse('POST', self::TOKEN_PATH, $response);
        }

        $ttl = max(60, $expiresIn - $this->refreshMargin);
        $this->cache->put(self::CACHE_KEY, $token, $ttl);

        Log::channel('ifood')->info('ifood.token.refreshed', ['expires_in' => $expiresIn, 'cached_for' => $ttl]);

        return $token;
    }
}
