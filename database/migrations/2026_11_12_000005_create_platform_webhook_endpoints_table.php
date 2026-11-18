<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->string('name', 128);
            // URL segment: final URL is /webhooks/platforms/{platform.slug}/{key}.
            $table->string('key', 64);
            $table->string('verification_strategy', 64);
            $table->json('verification_config')->nullable();
            $table->integer('log_retention_seconds')->default(2592000);
            $table->integer('max_retries')->default(5);
            $table->json('topic_filter')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['platform_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_webhook_endpoints');
    }
};
