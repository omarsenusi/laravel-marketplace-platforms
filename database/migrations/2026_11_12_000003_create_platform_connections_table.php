<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $workspaces = (string) config('platforms.tables.workspaces', 'workspaces');

        Schema::create('platform_connections', function (Blueprint $table) use ($workspaces) {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_id')->constrained('platforms')->cascadeOnDelete();
            // One store per workspace: a workspace connects a given
            // platform at most once.
            $table->foreignUuid('workspace_id')->unique()->constrained($workspaces)->cascadeOnDelete();
            $table->string('external_store_id', 128);
            $table->string('status', 32)->default('pending_oauth');
            $table->text('tokens')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_scheduled_at')->nullable();
            $table->timestamp('oauth_completed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'external_store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_connections');
    }
};
