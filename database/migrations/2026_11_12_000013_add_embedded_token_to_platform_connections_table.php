<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_connections', function (Blueprint $table): void {
            $table->string('embedded_token_hash', 64)->nullable()->after('subscription_event_at');
            $table->index('embedded_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('platform_connections', function (Blueprint $table): void {
            $table->dropIndex(['embedded_token_hash']);
            $table->dropColumn('embedded_token_hash');
        });
    }
};
