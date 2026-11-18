<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marketplace-installed owners start with a NULL password: the
        // account exists but cannot authenticate until the workspace is
        // claimed (claim page) or a password reset completes.
        //
        // This migration ALTERS THE HOST'S users table (see
        // platforms.tables.users). Every change is guarded with
        // hasTable/hasColumn so hosts that already ship a nullable
        // password or a phone column are untouched. Hosts whose users
        // table differs more fundamentally should skip this migration
        // and implement the equivalent themselves.
        $users = (string) config('platforms.tables.users', 'users');

        if (! Schema::hasTable($users)) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite (tests + small installs): rebuild via a fresh
            // nullable column copy. `change()` on SQLite also works on
            // Laravel 11+, but an explicit copy avoids edge cases with
            // the `hashed` cast default on older table copies.
            if (! Schema::hasColumn($users, 'phone')) {
                Schema::table($users, function (Blueprint $table) {
                    $table->string('phone', 32)->nullable()->after('email');
                });
            }

            Schema::table($users, function (Blueprint $table) {
                $table->string('password')->nullable()->change();
            });
        } else {
            Schema::table($users, function (Blueprint $table) use ($users) {
                if (! Schema::hasColumn($users, 'phone')) {
                    $table->string('phone', 32)->nullable()->after('email');
                }

                $table->string('password')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $users = (string) config('platforms.tables.users', 'users');

        if (! Schema::hasTable($users)) {
            return;
        }

        Schema::table($users, function (Blueprint $table) use ($users) {
            if (Schema::hasColumn($users, 'phone')) {
                $table->dropColumn('phone');
            }
        });
    }
};
