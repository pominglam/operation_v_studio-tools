<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plamod_restock_reorder_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->unsignedInteger('reorder_qty');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plamod_restock_reorder_overrides');
    }
};
