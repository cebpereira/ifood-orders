<?php

declare(strict_types=1);

use App\Models\IfoodEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Decoded JSON fixture from tests/Fixtures/ifood. Fixtures are synthetic:
 * never paste real payloads here (see CLAUDE.md).
 *
 * @return array<mixed>
 */
function ifoodFixture(string $name): array
{
    $json = file_get_contents(__DIR__."/Fixtures/ifood/{$name}.json");

    return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
}

/** Absolute URL on the fake iFood host used in tests (phpunit.xml). */
function ifoodUrl(string $path): string
{
    return 'https://merchant-api.ifood.test'.$path;
}

const TEST_ORDER_ID = '0a000000-0000-4000-8000-0000000000aa';

/** A stored (not yet processed) event; defaults to a PLC for TEST_ORDER_ID. */
function storedEvent(array $attributes = []): IfoodEvent
{
    $id = (string) Str::uuid();

    return IfoodEvent::create([
        'event_id' => $id,
        'code' => 'PLC',
        'full_code' => 'PLACED',
        'order_id' => TEST_ORDER_ID,
        'merchant_id' => '00000000-0000-0000-0000-000000000001',
        'event_created_at' => now(),
        'payload' => ['id' => $id],
        ...$attributes,
    ]);
}

/**
 * Store an event with a given code and createdAt, as polling would.
 *
 * @param  array<string, mixed>  $metadata
 */
function eventFor(string $code, string $at, string $orderId = TEST_ORDER_ID, array $metadata = []): IfoodEvent
{
    $fullCodes = [
        'PLC' => 'PLACED', 'CFM' => 'CONFIRMED', 'PRS' => 'PREPARATION_STARTED', 'RTP' => 'READY_TO_PICKUP',
        'DSP' => 'DISPATCHED', 'CON' => 'CONCLUDED', 'CAN' => 'CANCELLED', 'CAR' => 'CANCELLATION_REQUESTED',
        'CARF' => 'CANCELLATION_REQUEST_FAILED',
    ];

    return storedEvent([
        'code' => $code,
        'full_code' => $fullCodes[$code] ?? $code,
        'order_id' => $orderId,
        'event_created_at' => $at,
        'metadata' => $metadata === [] ? null : $metadata,
    ]);
}
