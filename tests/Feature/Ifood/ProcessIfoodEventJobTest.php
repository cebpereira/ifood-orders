<?php

declare(strict_types=1);

use App\Ifood\Enums\IfoodEventStatus;
use App\Ifood\Events\EventProcessor;
use App\Ifood\Exceptions\IfoodRateLimitedException;
use App\Jobs\FetchOrderDetailsJob;
use App\Jobs\ProcessIfoodEventJob;
use App\Models\IfoodEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Bus::fake([FetchOrderDetailsJob::class]));

function failingProcessor(Throwable $e): void
{
    app()->instance(EventProcessor::class, new class($e) extends EventProcessor
    {
        public function __construct(private Throwable $e) {}

        public function process(IfoodEvent $event): void
        {
            throw $this->e;
        }
    });
}

it('marks a known event as processed', function (): void {
    $event = storedEvent();

    ProcessIfoodEventJob::dispatchSync($event->id);

    expect($event->refresh()->status)->toBe(IfoodEventStatus::Processed)
        ->and($event->processed_at)->not->toBeNull();
});

it('ignores codes it does not know, without failing', function (): void {
    $event = storedEvent(['code' => 'DDCR', 'full_code' => 'DELIVERY_DROP_CODE_REQUESTED']);

    ProcessIfoodEventJob::dispatchSync($event->id);

    expect($event->refresh()->status)->toBe(IfoodEventStatus::Ignored)
        ->and($event->ignore_reason)->toBe('unknown_code');
});

it('is a no-op for an event already processed', function (): void {
    $event = storedEvent(['status' => IfoodEventStatus::Processed, 'processed_at' => now()->subHour()]);
    $before = $event->processed_at;
    failingProcessor(new RuntimeException('must not run'));

    ProcessIfoodEventJob::dispatchSync($event->id);

    expect($event->refresh()->processed_at->equalTo($before))->toBeTrue();
});

it('is a no-op for an event that no longer exists', function (): void {
    ProcessIfoodEventJob::dispatchSync(999_999);
})->throwsNoExceptions();

it('records the failed attempt and rethrows so the queue retries', function (): void {
    $event = storedEvent();
    failingProcessor(new RuntimeException('database hiccup'));

    expect(fn () => (new ProcessIfoodEventJob($event->id))->handle(app(EventProcessor::class)))
        ->toThrow(RuntimeException::class, 'database hiccup');

    expect($event->refresh())
        ->status->toBe(IfoodEventStatus::Received)
        ->attempts->toBe(1)
        ->last_error->toContain('database hiccup');
});

it('marks the event as failed with a backoff once the job gives up', function (): void {
    $this->freezeSecond();
    $event = storedEvent(['attempts' => 3]);

    (new ProcessIfoodEventJob($event->id))->failed(new RuntimeException('boom'));

    expect($event->refresh())
        ->status->toBe(IfoodEventStatus::Failed)
        ->and($event->next_retry_at->equalTo(now()->addMinutes(2)))->toBeTrue();
});

it('releases on rate limit without spending an attempt', function (): void {
    $event = storedEvent();
    failingProcessor(IfoodRateLimitedException::until(25));

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(25);
    $job = new ProcessIfoodEventJob($event->id);
    $job->setJob($queueJob);

    $job->handle(app(EventProcessor::class));

    expect($event->refresh())
        ->status->toBe(IfoodEventStatus::Received)
        ->attempts->toBe(0);
});

it('serializes jobs of the same order', function (): void {
    $a = storedEvent();
    $b = storedEvent(['code' => 'CFM', 'full_code' => 'CONFIRMED']);

    $keyOf = fn (IfoodEvent $e) => (new ProcessIfoodEventJob($e->id))->middleware()[0]->getLockKey(new ProcessIfoodEventJob($e->id));

    expect($keyOf($a))->toBe($keyOf($b))
        ->and($keyOf($a))->toContain('ifood-order:0a000000-0000-4000-8000-0000000000aa');
});

describe('ifood:retry', function (): void {
    beforeEach(fn () => Queue::fake());

    it('re-dispatches failed events whose backoff elapsed', function (): void {
        $due = storedEvent(['status' => IfoodEventStatus::Failed, 'attempts' => 3, 'next_retry_at' => now()->subMinute()]);
        storedEvent(['status' => IfoodEventStatus::Failed, 'attempts' => 3, 'next_retry_at' => now()->addMinute()]);
        storedEvent(['status' => IfoodEventStatus::Failed, 'attempts' => IfoodEvent::MAX_ATTEMPTS, 'next_retry_at' => now()->subMinute()]);
        storedEvent(['status' => IfoodEventStatus::Processed]);

        $this->artisan('ifood:retry')->expectsOutputToContain('Dispatched 1 event(s)')->assertSuccessful();

        Queue::assertPushed(ProcessIfoodEventJob::class, fn (ProcessIfoodEventJob $job) => $job->ifoodEventId === $due->id);
        Queue::assertPushed(ProcessIfoodEventJob::class, 1);
        expect($due->refresh()->status)->toBe(IfoodEventStatus::Received);
    });

    it('recovers received events that were never processed', function (): void {
        $lost = storedEvent();
        $lost->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
        storedEvent(); // fresh, its job is probably still in the queue

        $this->artisan('ifood:retry')->assertSuccessful();

        Queue::assertPushed(ProcessIfoodEventJob::class, 1);
        Queue::assertPushed(ProcessIfoodEventJob::class, fn (ProcessIfoodEventJob $job) => $job->ifoodEventId === $lost->id);
    });

    it('retries specific events by id, even past the automatic limit', function (): void {
        $stuck = storedEvent(['status' => IfoodEventStatus::Failed, 'attempts' => 50]);

        $this->artisan('ifood:retry', ['--id' => [$stuck->id]])->assertSuccessful();

        Queue::assertPushed(ProcessIfoodEventJob::class, 1);
    });

    it('only lists events on dry run', function (): void {
        storedEvent(['status' => IfoodEventStatus::Failed, 'attempts' => 1, 'next_retry_at' => now()->subMinute()]);

        $this->artisan('ifood:retry', ['--dry-run' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    });
});
