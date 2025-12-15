<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_research_run_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('run_id');
            $table->uuid('run_uuid');

            $table->unsignedBigInteger('product_id');
            $table->uuid('product_uuid');
            $table->string('sku', 64);

            $table->string('site_key', 64);
            $table->string('site_name', 128);

            // running | found | not_found | error | exception
            $table->string('status', 32);

            $table->text('product_url')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->foreign('run_id', 'fk_price_research_run_logs_run_id')
                ->references('id')->on('price_research_runs')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('product_id', 'fk_price_research_run_logs_product_id')
                ->references('id')->on('products')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->index(['run_id', 'created_at'], 'idx_price_research_run_logs_run_created');
            $table->index(['product_id', 'created_at'], 'idx_price_research_run_logs_product_created');
            $table->index(['site_key', 'created_at'], 'idx_price_research_run_logs_site_created');
            $table->index(['status', 'created_at'], 'idx_price_research_run_logs_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_research_run_logs');
    }
};
