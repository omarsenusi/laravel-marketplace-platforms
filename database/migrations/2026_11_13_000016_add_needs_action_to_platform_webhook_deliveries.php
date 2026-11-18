<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flag deliveries that were processed but left work for the host to
 * review (e.g. a subscription for an unmapped plan), so the delivery
 * reads "Needs action" instead of a plain "Processed".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_webhook_deliveries', function (Blueprint $table): void {
            $table->boolean('needs_action')->default(false)->after('outcome');
            $table->index(['platform_id', 'needs_action']);
        });

        // Backfill rows recorded before the flag existed.
        DB::table('platform_webhook_deliveries')
            ->where('outcome', 'like', 'Not applied:%')
            ->update(['needs_action' => true]);
    }

    public function down(): void
    {
        Schema::table('platform_webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['platform_id', 'needs_action']);
            $table->dropColumn('needs_action');
        });
    }
};
