<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('ifood_order_id')->unique();
            $table->uuid('merchant_id');
            $table->string('status', 32)->index();

            // From the first event we see, whatever its code.
            $table->timestampTz('first_event_at', 3);
            // createdAt of the event that set the current status.
            $table->timestampTz('status_changed_at', 3);
            // From the PLC event, even when it arrives after later events.
            $table->timestampTz('placed_at', 3)->nullable();
            $table->timestampTz('confirm_deadline_at', 3)->nullable()->index();

            $table->timestampTz('cancellation_requested_at', 3)->nullable();
            $table->string('cancellation_code', 16)->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('cancellation_origin', 32)->nullable();

            // Filled by FetchOrderDetailsJob.
            $table->string('display_id', 32)->nullable();
            $table->string('order_type', 32)->nullable();
            $table->string('category', 32)->nullable();
            $table->string('sales_channel', 32)->nullable();
            $table->string('customer_name')->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->boolean('is_test')->nullable();
            $table->jsonb('details')->nullable();
            $table->timestampTz('details_fetched_at', 3)->nullable();
            $table->string('details_error')->nullable();

            $table->timestampsTz(3);
        });

        Schema::create('order_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // One row per event: reprocessing an event never duplicates its history.
            $table->foreignId('ifood_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('event_code', 16);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->boolean('applied');
            $table->string('reason', 64)->nullable();
            $table->timestampTz('occurred_at', 3);
            $table->timestampTz('created_at', 3)->useCurrent();

            $table->index(['order_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_transitions');
        Schema::dropIfExists('orders');
    }
};
