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
            $table->decimal('cash_received_cad', 12, 2)->nullable()->after('balance_received_at');
            $table->timestamp('cash_received_at')->nullable()->after('cash_received_cad');
        });
    }

    public function down(): void
    {
        Schema::table('special_orders', function (Blueprint $table): void {
            $table->dropColumn(['cash_received_cad', 'cash_received_at']);
        });
    }
};
