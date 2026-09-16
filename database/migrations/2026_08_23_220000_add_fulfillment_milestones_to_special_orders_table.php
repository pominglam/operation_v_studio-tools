<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_orders', function (Blueprint $table): void {
            $table->timestamp('deposit_received_at')->nullable()->after('deposit_percent');
            $table->timestamp('merchandiser_ordered_at')->nullable()->after('deposit_received_at');
            $table->date('estimated_arrival_at')->nullable()->after('merchandiser_ordered_at');
            $table->index('estimated_arrival_at', 'idx_special_orders_estimated_arrival_at');
        });
    }

    public function down(): void
    {
        Schema::table('special_orders', function (Blueprint $table): void {
            $table->dropIndex('idx_special_orders_estimated_arrival_at');
            $table->dropColumn([
                'deposit_received_at',
                'merchandiser_ordered_at',
                'estimated_arrival_at',
            ]);
        });
    }
};
