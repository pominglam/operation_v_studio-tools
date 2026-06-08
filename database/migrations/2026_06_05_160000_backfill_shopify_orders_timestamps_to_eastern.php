<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('shopify_orders')
            ->select(['id', 'ordered_at_shop_tz', 'graphql_updated_at', 'cancelled_at'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach (['ordered_at_shop_tz', 'graphql_updated_at', 'cancelled_at'] as $column) {
                        $value = $row->{$column} ?? null;
                        if (! is_string($value) || trim($value) === '') {
                            continue;
                        }

                        $eastern = Carbon::parse($value, 'UTC')->timezone('America/Toronto');
                        $updates[$column] = $eastern->format('Y-m-d H:i:s');
                    }

                    if ($updates !== []) {
                        DB::table('shopify_orders')->where('id', $row->id)->update($updates);
                    }
                }
            });

        DB::table('shopify_sync_state')
            ->where('sync_key', 'orders')
            ->whereNotNull('high_water_updated_at')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $value = $row->high_water_updated_at ?? null;
                    if (! is_string($value) || trim($value) === '') {
                        continue;
                    }

                    $eastern = Carbon::parse($value, 'UTC')->timezone('America/Toronto');
                    DB::table('shopify_sync_state')
                        ->where('id', $row->id)
                        ->update(['high_water_updated_at' => $eastern->format('Y-m-d H:i:s')]);
                }
            });
    }

    public function down(): void
    {
        DB::table('shopify_orders')
            ->select(['id', 'ordered_at_shop_tz', 'graphql_updated_at', 'cancelled_at'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach (['ordered_at_shop_tz', 'graphql_updated_at', 'cancelled_at'] as $column) {
                        $value = $row->{$column} ?? null;
                        if (! is_string($value) || trim($value) === '') {
                            continue;
                        }

                        $utc = Carbon::parse($value, 'America/Toronto')->utc();
                        $updates[$column] = $utc->format('Y-m-d H:i:s');
                    }

                    if ($updates !== []) {
                        DB::table('shopify_orders')->where('id', $row->id)->update($updates);
                    }
                }
            });

        DB::table('shopify_sync_state')
            ->where('sync_key', 'orders')
            ->whereNotNull('high_water_updated_at')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $value = $row->high_water_updated_at ?? null;
                    if (! is_string($value) || trim($value) === '') {
                        continue;
                    }

                    $utc = Carbon::parse($value, 'America/Toronto')->utc();
                    DB::table('shopify_sync_state')
                        ->where('id', $row->id)
                        ->update(['high_water_updated_at' => $utc->format('Y-m-d H:i:s')]);
                }
            });
    }
};
