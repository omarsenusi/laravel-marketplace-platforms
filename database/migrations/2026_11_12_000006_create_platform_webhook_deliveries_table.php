<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('endpoint_id')->constrained('platform_webhook_endpoints')->cascadeOnDelete();
            $table->foreignUuid('platform_id')->constrained('platforms')->index();
            $table->foreignUuid('connection_id')->nullable()->constrained('platform_connections')->nullOnDelete();
            $table->string('external_event_id', 256);
            $table->string('topic', 128)->nullable();
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('failure_reason')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->integer('max_attempts')->default(5);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();

            $table->unique(['endpoint_id', 'external_event_id']);
            $table->index(['platform_id', 'status']);
            $table->index('expires_at');
            $table->index('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_webhook_deliveries');
    }
};
