<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', static function (Blueprint $table): void {
            $table->boolean('is_hazardous_shipment')->default(false)->after('is_discontinued')->index();
            $table->string('shipment_method', 8)->nullable()->after('is_hazardous_shipment')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', static function (Blueprint $table): void {
            $table->dropIndex(['is_hazardous_shipment']);
            $table->dropIndex(['shipment_method']);
            $table->dropColumn(['is_hazardous_shipment', 'shipment_method']);
        });
    }
};
