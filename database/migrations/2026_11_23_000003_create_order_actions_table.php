<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('action', 32);
            // pending → sent (iFood answered 202) → confirmed | failed | expired
            $table->string('status', 16)->index();
            $table->string('cancellation_code', 16)->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error')->nullable();
            $table->foreignId('resolved_by_event_id')->nullable()->constrained('ifood_events')->nullOnDelete();
            $table->timestampTz('requested_at', 3);
            $table->timestampTz('sent_at', 3)->nullable();
            $table->timestampTz('expires_at', 3)->nullable()->index();
            $table->timestampTz('resolved_at', 3)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestampsTz(3);
        });

        // At most one open action per order: a double click cannot send two.
        DB::statement("CREATE UNIQUE INDEX order_actions_one_open_per_order ON order_actions (order_id) WHERE status IN ('pending', 'sent')");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_actions');
    }
};
