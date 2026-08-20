<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_tracking_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->char('tracking_key', 64);
            $table->string('tracking_number', 255);
            $table->string('status', 20)->default('queued');
            $table->string('provider', 40)->nullable();
            $table->string('tracking_url', 2048)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('retry_after')->nullable();
            $table->string('error_summary', 500)->nullable();
            $table->timestamps();

            $table->unique('tracking_key', 'unique_tracking_resolution_key');
            $table->index(['status', 'retry_after'], 'idx_tracking_resolution_retry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_resolutions');
    }
};
