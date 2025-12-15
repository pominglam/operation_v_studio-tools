<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_research_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('uniq_price_research_runs_uuid');

            $table->string('status', 32); // queued | running | completed | failed
            $table->boolean('force')->default(false);
            $table->unsignedInteger('ttl_days')->default(14);

            $table->unsignedInteger('total_products')->default(0);
            $table->unsignedInteger('processed_products')->default(0);
            $table->unsignedInteger('refreshed_products')->default(0);
            $table->unsignedInteger('skipped_fresh_products')->default(0);

            $table->unsignedInteger('total_sites')->default(0);
            $table->unsignedInteger('processed_sites')->default(0);
            $table->unsignedInteger('quotes_written')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_price_research_runs_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_research_runs');
    }
};
