<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->decimal('merchandiser_commission_override_cad', 10, 2)->nullable()->after('merchandiser_price_cad');
            $table->decimal('our_commission_override_cad', 10, 2)->nullable()->after('customer_price_cad');
            $table->decimal('deposit_amount_override_cad', 10, 2)->nullable()->after('deposit_percent');
        });
    }

    public function down(): void
    {
        Schema::table('custom_asia_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'merchandiser_commission_override_cad',
                'our_commission_override_cad',
                'deposit_amount_override_cad',
            ]);
        });
    }
};
