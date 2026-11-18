<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zid installs arrive as a webhook BEFORE OAuth, when no owner email
     * (and therefore no user/workspace) exists yet. The connection row
     * must exist in `pending_oauth` without a workspace, which is
     * attached when OAuth completes with a real email. NULLs are exempt
     * from the unique constraint on both pgsql and sqlite, so several
     * pending connections can coexist; (platform, store) stays unique.
     */
    public function up(): void
    {
        Schema::table('platform_connections', function (Blueprint $table) {
            $table->uuid('workspace_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately a no-op: re-imposing NOT NULL could fail on rows
        // that legitimately hold NULL (pending Zid installs).
    }
};
