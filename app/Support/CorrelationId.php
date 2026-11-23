<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Correlation id kept in Laravel's Context: it is attached to every log record
 * and travels with queued jobs, so a poll cycle and every job it dispatched
 * share the same id.
 */
final class CorrelationId
{
    public const string KEY = 'correlation_id';

    public static function get(): string
    {
        $id = Context::get(self::KEY);

        if (! is_string($id)) {
            $id = (string) Str::uuid();
            Context::add(self::KEY, $id);
        }

        return $id;
    }

    /** Start a new flow (e.g. a poll cycle) with a fresh id. */
    public static function start(): string
    {
        $id = (string) Str::uuid();
        Context::add(self::KEY, $id);

        return $id;
    }
}
