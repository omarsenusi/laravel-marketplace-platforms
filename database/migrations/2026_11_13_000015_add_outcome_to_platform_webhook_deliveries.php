<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a handler actually did with a delivery (e.g. "Not applied: plan
 * has no mapping"), so a "processed" status never hides a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_webhook_deliveries', function (Blueprint $table): void {
            $table->string('outcome', 500)->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('platform_webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('outcome');
        });
    }
};
