<?php

declare(strict_types=1);

namespace App\Ifood\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One event from the polling endpoint. `code` stays a string here: unknown
 * codes (e.g. DDCR, seen in discovery) must still be stored and acked.
 */
final readonly class PolledEvent
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $fullCode,
        public string $orderId,
        public string $merchantId,
        public CarbonImmutable $createdAt,
        public ?string $salesChannel,
        public array $metadata,
        public array $raw,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'code', 'fullCode', 'orderId', 'merchantId', 'createdAt'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || $data[$field] === '') {
                throw new InvalidArgumentException("iFood event is missing [{$field}]");
            }
        }

        /** @var array<string, mixed> $raw */
        $raw = $data;
        $metadata = $data['metadata'] ?? [];

        return new self(
            id: $data['id'],
            code: $data['code'],
            fullCode: $data['fullCode'],
            orderId: $data['orderId'],
            merchantId: $data['merchantId'],
            createdAt: CarbonImmutable::parse($data['createdAt'])->utc(),
            salesChannel: is_string($data['salesChannel'] ?? null) ? $data['salesChannel'] : null,
            metadata: is_array($metadata) ? $metadata : [],
            raw: $raw,
        );
    }
}
