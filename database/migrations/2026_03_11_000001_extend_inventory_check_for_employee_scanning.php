<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_check', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_check', 'workflow_state')) {
                $table->string('workflow_state', 32)->default('draft')->after('notes');
                $table->index(['workflow_state'], 'idx_inventory_check_workflow_state');
            }
            if (! Schema::hasColumn('inventory_check', 'created_by_role')) {
                $table->string('created_by_role', 32)->nullable()->after('workflow_state');
            }
            if (! Schema::hasColumn('inventory_check', 'applied_at')) {
                $table->timestamp('applied_at')->nullable()->after('created_by_role');
            }
        });

        Schema::table('inventory_check_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_check_items', 'barcode_scanned')) {
                $table->string('barcode_scanned', 64)->nullable()->after('product_id');
                $table->index(['barcode_scanned'], 'idx_inventory_check_items_barcode_scanned');
            }
            if (! Schema::hasColumn('inventory_check_items', 'issue_flag')) {
                $table->boolean('issue_flag')->default(false)->after('match_error');
                $table->index(['issue_flag'], 'idx_inventory_check_items_issue_flag');
            }
            if (! Schema::hasColumn('inventory_check_items', 'issue_reason')) {
                $table->string('issue_reason', 255)->nullable()->after('issue_flag');
            }
            if (! Schema::hasColumn('inventory_check_items', 'selling_price_snapshot')) {
                $table->decimal('selling_price_snapshot', 10, 2)->nullable()->after('available_amount');
            }
            if (! Schema::hasColumn('inventory_check_items', 'landed_unit_cost_snapshot')) {
                $table->decimal('landed_unit_cost_snapshot', 10, 2)->nullable()->after('selling_price_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_check_items', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_check_items', 'landed_unit_cost_snapshot')) {
                $table->dropColumn('landed_unit_cost_snapshot');
            }
            if (Schema::hasColumn('inventory_check_items', 'selling_price_snapshot')) {
                $table->dropColumn('selling_price_snapshot');
            }
            if (Schema::hasColumn('inventory_check_items', 'issue_reason')) {
                $table->dropColumn('issue_reason');
            }
            if (Schema::hasColumn('inventory_check_items', 'issue_flag')) {
                try {
                    $table->dropIndex('idx_inventory_check_items_issue_flag');
                } catch (\Throwable) {
                }
                $table->dropColumn('issue_flag');
            }
            if (Schema::hasColumn('inventory_check_items', 'barcode_scanned')) {
                try {
                    $table->dropIndex('idx_inventory_check_items_barcode_scanned');
                } catch (\Throwable) {
                }
                $table->dropColumn('barcode_scanned');
            }
        });

        Schema::table('inventory_check', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_check', 'applied_at')) {
                $table->dropColumn('applied_at');
            }
            if (Schema::hasColumn('inventory_check', 'created_by_role')) {
                $table->dropColumn('created_by_role');
            }
            if (Schema::hasColumn('inventory_check', 'workflow_state')) {
                try {
                    $table->dropIndex('idx_inventory_check_workflow_state');
                } catch (\Throwable) {
                }
                $table->dropColumn('workflow_state');
            }
        });
    }
};

