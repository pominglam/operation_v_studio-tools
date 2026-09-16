<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_saved_views', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_product_saved_views_uuid');
            $table->string('name', 40);
            $table->json('snapshot');
            $table->json('visible_columns');
            $table->timestamps();

            $table->unique('name', 'unique_product_saved_views_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_saved_views');
    }
};
