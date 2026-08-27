<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_taxonomy_research_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->string('status', 20)->default('queued');
            $table->string('research_version', 64);
            $table->json('checkpoint_json')->nullable();
            $table->json('counts_json')->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('uuid', 'unique_product_taxonomy_research_run_uuid');
            $table->index(['status', 'created_at'], 'idx_product_taxonomy_research_run_status');
            $table->index('research_version', 'idx_product_taxonomy_research_run_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_taxonomy_research_runs');
    }
};
