<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $indexRenames = [
        'idx_custom_asia_orders_media_created' => 'idx_special_orders_media_created',
        'idx_custom_asia_orders_landed_created' => 'idx_special_orders_landed_created',
        'idx_custom_asia_orders_created_at' => 'idx_special_orders_created_at',
        'idx_custom_asia_orders_updated_at' => 'idx_special_orders_updated_at',
        'idx_custom_asia_orders_product_name' => 'idx_special_orders_product_name',
        'idx_custom_asia_orders_customer_price_cad' => 'idx_special_orders_customer_price_cad',
        'idx_custom_asia_orders_receive_delay_days' => 'idx_special_orders_receive_delay_days',
        'idx_custom_asia_orders_estimated_arrival_at' => 'idx_special_orders_estimated_arrival_at',
        'idx_custom_asia_orders_product_received_at' => 'idx_special_orders_product_received_at',
        'unique_custom_asia_orders_uuid' => 'unique_special_orders_uuid',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('custom_asia_orders')) {
            return;
        }

        if (Schema::hasTable('special_orders')) {
            $legacyCount = (int) DB::table('custom_asia_orders')->count();
            $targetCount = (int) DB::table('special_orders')->count();

            if ($targetCount === 0 && $legacyCount > 0) {
                DB::statement('INSERT INTO special_orders SELECT * FROM custom_asia_orders');
                $this->migrateVisualStoragePaths();
            }

            if ($legacyCount > 0 && (int) DB::table('special_orders')->count() < $legacyCount) {
                throw new \RuntimeException(
                    'Refusing to drop custom_asia_orders: special_orders row count is lower than legacy table.',
                );
            }

            Schema::drop('custom_asia_orders');

            return;
        }

        Schema::rename('custom_asia_orders', 'special_orders');

        $this->renameLegacyIndexes();
        $this->migrateMaintenanceNoteKeys();
        $this->migrateVisualStoragePaths();
    }

    public function down(): void
    {
        if (! Schema::hasTable('special_orders')) {
            return;
        }

        if (! Schema::hasTable('custom_asia_orders')) {
            Schema::rename('special_orders', 'custom_asia_orders');
            $this->renameLegacyIndexes(down: true);
        }

        $this->migrateMaintenanceNoteKeys(down: true);
        $this->migrateVisualStoragePaths(down: true);
    }

    private function renameLegacyIndexes(bool $down = false): void
    {
        $pairs = $down ? array_flip($this->indexRenames) : $this->indexRenames;

        foreach ($pairs as $from => $to) {
            $exists = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                ['special_orders', $from],
            );

            if ($exists !== null) {
                DB::statement(sprintf('ALTER TABLE special_orders RENAME INDEX `%s` TO `%s`', $from, $to));
            }
        }
    }

    private function migrateMaintenanceNoteKeys(bool $down = false): void
    {
        if (! Schema::hasTable('maintenance_notes')) {
            return;
        }

        if ($down) {
            DB::table('maintenance_notes')
                ->where('key', 'special_order_customer_message')
                ->update(['key' => 'custom_asia_customer_message']);

            DB::table('maintenance_notes')
                ->where('key', 'special_order_pricing_caps')
                ->update(['key' => 'custom_asia_pricing_caps']);

            return;
        }

        DB::table('maintenance_notes')
            ->where('key', 'custom_asia_customer_message')
            ->update(['key' => 'special_order_customer_message']);

        DB::table('maintenance_notes')
            ->where('key', 'custom_asia_pricing_caps')
            ->update(['key' => 'special_order_pricing_caps']);
    }

    private function migrateVisualStoragePaths(bool $down = false): void
    {
        if (! Schema::hasTable('special_orders')) {
            return;
        }

        $from = $down ? 'special_orders/' : 'custom_asia_orders/';
        $to = $down ? 'custom_asia_orders/' : 'special_orders/';

        foreach (['customer_visual_path', 'product_visual_path', 'merchandiser_order_proof_path'] as $column) {
            DB::statement(
                "UPDATE special_orders SET {$column} = REPLACE({$column}, ?, ?) WHERE {$column} LIKE ?",
                [$from, $to, $from.'%'],
            );
        }
    }
};
