<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plamod_preorder_offers', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64)->index();
            $table->string('offer_key', 64);
            $table->string('offer_id', 32)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->date('eta_date')->nullable();
            $table->date('po_due_date')->nullable();
            $table->decimal('price_preorder', 12, 2)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['sku', 'offer_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plamod_preorder_offers');
    }
};
