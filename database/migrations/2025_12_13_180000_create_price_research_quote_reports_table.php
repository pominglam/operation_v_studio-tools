<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_research_quote_reports', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('product_id');
            $table->uuid('product_uuid');
            $table->string('sku', 64);

            $table->string('site_key', 64);
            $table->string('site_name', 128);

            // Snapshot of the quote at report time (so we can debug later even if the quote changes).
            $table->string('status', 32)->nullable();
            $table->string('availability', 64)->nullable();
            $table->string('currency', 8)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('original_price', 12, 2)->nullable();
            $table->text('product_url')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->uuid('run_uuid')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->foreign('product_id', 'fk_price_research_quote_reports_product_id')
                ->references('id')->on('products')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->index(['product_id', 'created_at'], 'idx_pr_quote_reports_product_created');
            $table->index(['site_key', 'created_at'], 'idx_pr_quote_reports_site_created');
            $table->index(['run_uuid', 'created_at'], 'idx_pr_quote_reports_run_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_research_quote_reports');
    }
};
