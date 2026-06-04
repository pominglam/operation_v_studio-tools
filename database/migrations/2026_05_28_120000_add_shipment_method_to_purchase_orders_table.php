<?php

declare(strict_types=1);

use App\Services\PurchaseOrders\PurchaseOrderShipmentMethodService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('shipment_method', 8)->nullable()->after('vendor')->index();
        });

        $service = app(PurchaseOrderShipmentMethodService::class);

        DB::table('purchase_orders')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (mixed $id) use ($service): void {
                $poId = (int) $id;
                $inferred = $service->inferFromPurchaseOrderId($poId);
                if ($inferred === null) {
                    return;
                }

                DB::table('purchase_orders')
                    ->where('id', $poId)
                    ->update(['shipment_method' => $inferred]);
            });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex(['shipment_method']);
            $table->dropColumn('shipment_method');
        });
    }
};
