<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\NullHandler;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests never depend on a front-end build, and never reach the real iFood.
        $this->withoutVite();
        Http::preventStrayRequests();

        // Keep test runs out of storage/logs/ifood-*.log. Log events still fire,
        // so tests can assert on what was logged (see MessageLogged).
        config(['logging.channels.ifood' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
    }
}
