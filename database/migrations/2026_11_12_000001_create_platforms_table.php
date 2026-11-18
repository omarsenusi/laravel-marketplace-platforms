<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platforms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 32)->unique();
            $table->string('name', 128);
            $table->string('driver', 32);
            $table->string('app_id', 128)->nullable();
            $table->string('client_id', 256)->nullable()->index();
            $table->string('auth_mode', 32);
            $table->text('marketplace_app_url')->nullable();
            $table->boolean('supports_marketplace_subscriptions')->default(false);
            $table->string('status', 16)->default('draft')->index();
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platforms');
    }
};
