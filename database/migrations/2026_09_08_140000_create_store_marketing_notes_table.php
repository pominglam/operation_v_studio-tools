<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_marketing_notes', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_store_marketing_notes_uuid');
            $table->date('happened_on');
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('happened_on', 'idx_store_marketing_notes_happened_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_marketing_notes');
    }
};
