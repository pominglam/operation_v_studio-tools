<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plamod_restock_planned_maintain', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->unsignedInteger('maintain_qty');
            $table->timestamp('applied_at')->nullable()->index();
            $table->timestamps();
        });

        if (Schema::hasColumn('plamod_restock_sku_decisions', 'planned_maintain_qty')) {
            DB::table('plamod_restock_sku_decisions')
                ->whereNotNull('planned_maintain_qty')
                ->orderBy('id')
                ->each(function (object $row): void {
                    DB::table('plamod_restock_planned_maintain')->updateOrInsert(
                        ['sku' => $row->sku],
                        [
                            'maintain_qty' => (int) $row->planned_maintain_qty,
                            'applied_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                });
        }

        Schema::table('plamod_restock_sku_decisions', function (Blueprint $table): void {
            $table->dropColumn('planned_maintain_qty');
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropColumn('planned_maintain_qty');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->unsignedInteger('planned_maintain_qty')->nullable()->after('qty_received');
        });

        Schema::table('plamod_restock_sku_decisions', function (Blueprint $table): void {
            $table->unsignedInteger('planned_maintain_qty')->nullable()->after('order_qty');
        });

        Schema::dropIfExists('plamod_restock_planned_maintain');
    }
};
