<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_plan_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_id')->constrained('platforms')->cascadeOnDelete();
            $table->string('external_plan_key', 191);
            $table->string('label')->nullable();
            // Host-owned plan reference: a plain uuid with NO foreign
            // key — the host app owns the plans table.
            $table->uuid('plan_id')->nullable();
            $table->timestamps();

            $table->unique(['platform_id', 'external_plan_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_plan_mappings');
    }
};
