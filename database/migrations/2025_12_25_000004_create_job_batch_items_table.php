<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_batch_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('batch_id');
            $table->uuid('product_uuid');
            $table->string('sku')->nullable();
            $table->string('vendor')->nullable();
            $table->string('status'); // queued|running|succeeded|failed|skipped
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('sync_uuid')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
            $table->unique(['batch_id', 'product_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_batch_items');
    }
};


