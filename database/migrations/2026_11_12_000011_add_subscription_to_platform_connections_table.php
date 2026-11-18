<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_connections', function (Blueprint $table): void {
            $table->json('subscription')->nullable()->after('oauth_completed_at');
            $table->timestamp('subscription_event_at')->nullable()->after('subscription');
        });
    }

    public function down(): void
    {
        Schema::table('platform_connections', function (Blueprint $table): void {
            $table->dropColumn(['subscription_event_at', 'subscription']);
        });
    }
};
