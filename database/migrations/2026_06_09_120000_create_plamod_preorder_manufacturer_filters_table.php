<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plamod_preorder_manufacturer_filters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('manufacturer_id')->default(1);
            $table->string('filter_type', 32);
            $table->string('name', 255);
            $table->unsignedInteger('plamod_preorder_count')->nullable();
            $table->unsignedInteger('plamod_other_count')->nullable();
            $table->string('decision', 32)->default('undecided');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['manufacturer_id', 'filter_type', 'name'], 'plamod_mfr_filters_unique');
            $table->index(['manufacturer_id', 'decision'], 'plamod_mfr_filters_mfr_decision_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plamod_preorder_manufacturer_filters');
    }
};
