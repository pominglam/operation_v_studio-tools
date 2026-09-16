<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('unique_store_events_uuid');
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['starts_on', 'ends_on'], 'idx_store_events_dates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_events');
    }
};
