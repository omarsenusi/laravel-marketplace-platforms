<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Host tables come from platforms.tables.* so the package never
        // hard-codes the host schema. Key types must match: workspaces
        // use a uuid primary key, users a bigint primary key.
        $workspaces = (string) config('platforms.tables.workspaces', 'workspaces');
        $users = (string) config('platforms.tables.users', 'users');

        Schema::create('workspace_claims', function (Blueprint $table) use ($workspaces, $users) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained($workspaces)->cascadeOnDelete();
            $table->foreignId('user_id')->constrained($users)->cascadeOnDelete();
            $table->foreignUuid('connection_id')->nullable()->constrained('platform_connections')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedTinyInteger('reminder_count')->default(0);
            $table->string('created_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_claims');
    }
};
