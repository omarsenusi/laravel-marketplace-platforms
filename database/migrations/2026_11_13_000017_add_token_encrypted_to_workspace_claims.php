<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted (APP_KEY) copy of the raw claim token so the CURRENT
 * setup link can be re-issued instead of minting a new one that would
 * kill the link already emailed to the merchant. Lookups still use
 * token_hash; this column is only ever decrypted for link recovery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_claims', function (Blueprint $table): void {
            $table->text('token_encrypted')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_claims', function (Blueprint $table): void {
            $table->dropColumn('token_encrypted');
        });
    }
};
