<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ifood_events', function (Blueprint $table): void {
            $table->id();

            // iFood's event id. The unique index is what makes intake idempotent:
            // a redelivered event is ignored by the database, not by app logic.
            $table->uuid('event_id')->unique();
            $table->string('code', 16);
            $table->string('full_code', 64);
            $table->uuid('order_id')->index();
            $table->uuid('merchant_id');
            $table->string('sales_channel', 32)->nullable();
            // Millisecond precision matters: CAR and CAN can be ~150ms apart.
            $table->timestampTz('event_created_at', 3)->index();
            $table->jsonb('metadata')->nullable();
            $table->jsonb('payload');

            $table->string('status', 16)->default('received')->index();
            $table->string('ignore_reason', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('next_retry_at', 3)->nullable()->index();

            $table->timestampTz('acknowledged_at', 3)->nullable();
            $table->timestampTz('processed_at', 3)->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestampsTz(3);

            $table->index(['order_id', 'event_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ifood_events');
    }
};
