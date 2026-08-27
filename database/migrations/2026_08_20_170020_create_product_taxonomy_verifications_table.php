<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_taxonomy_verifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('product_id');
            $table->foreignId('research_run_id')->nullable();
            $table->string('status', 20)->default('proposed');
            $table->string('research_version', 64);
            $table->json('proposed_values_json');
            $table->json('previous_values_json');
            $table->json('evidence_json');
            $table->unsignedTinyInteger('overall_confidence');
            $table->string('research_method', 64);
            $table->text('operator_notes')->nullable();
            $table->timestamp('researched_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->string('verified_by', 255)->nullable();
            $table->timestamps();

            $table->unique('uuid', 'unique_product_taxonomy_verification_uuid');
            $table->index(
                ['product_id', 'status', 'created_at'],
                'idx_product_taxonomy_verification_product_status',
            );
            $table->index(
                ['status', 'overall_confidence'],
                'idx_product_taxonomy_verification_review',
            );
            $table->index('research_version', 'idx_product_taxonomy_verification_version');
            $table->foreign('product_id', 'fk_product_taxonomy_verification_product')
                ->references('id')
                ->on('products')
                ->restrictOnDelete()
                ->restrictOnUpdate();
            $table->foreign('research_run_id', 'fk_product_taxonomy_verification_run')
                ->references('id')
                ->on('product_taxonomy_research_runs')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_taxonomy_verifications');
    }
};
