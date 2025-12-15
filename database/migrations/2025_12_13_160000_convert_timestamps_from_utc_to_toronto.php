<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Heuristic guard: only run if it looks like timestamps were stored using UTC "wall clock"
        // (e.g. started_at is close to UTC_TIMESTAMP() but far from NOW()).
        $sample = DB::selectOne(
            'SELECT
                ABS(TIMESTAMPDIFF(MINUTE, started_at, UTC_TIMESTAMP())) AS mins_from_utc,
                ABS(TIMESTAMPDIFF(MINUTE, started_at, NOW())) AS mins_from_local
            FROM price_research_runs
            WHERE started_at IS NOT NULL
            ORDER BY id DESC
            LIMIT 1'
        );

        if ($sample === null) {
            return;
        }

        $minsFromUtc = (int) ($sample->mins_from_utc ?? 0);
        $minsFromLocal = (int) ($sample->mins_from_local ?? 0);

        // If the latest started_at is not closer to UTC than local time, assume we don't need conversion.
        if ($minsFromUtc >= $minsFromLocal) {
            return;
        }

        // Ensure this session is Toronto when computing CONVERT_TZ against TIMESTAMP columns.
        DB::statement("SET time_zone = 'America/Toronto'");

        // Convert all TIMESTAMP columns that represent Laravel-managed timestamps.
        DB::statement("UPDATE price_research_runs SET started_at = CONVERT_TZ(started_at, 'UTC', 'America/Toronto') WHERE started_at IS NOT NULL");
        DB::statement("UPDATE price_research_runs SET finished_at = CONVERT_TZ(finished_at, 'UTC', 'America/Toronto') WHERE finished_at IS NOT NULL");
        DB::statement("UPDATE price_research_runs SET created_at = CONVERT_TZ(created_at, 'UTC', 'America/Toronto') WHERE created_at IS NOT NULL");
        DB::statement("UPDATE price_research_runs SET updated_at = CONVERT_TZ(updated_at, 'UTC', 'America/Toronto') WHERE updated_at IS NOT NULL");

        DB::statement("UPDATE products SET created_at = CONVERT_TZ(created_at, 'UTC', 'America/Toronto') WHERE created_at IS NOT NULL");
        DB::statement("UPDATE products SET updated_at = CONVERT_TZ(updated_at, 'UTC', 'America/Toronto') WHERE updated_at IS NOT NULL");
        DB::statement("UPDATE products SET price_researched_at = CONVERT_TZ(price_researched_at, 'UTC', 'America/Toronto') WHERE price_researched_at IS NOT NULL");

        DB::statement("UPDATE product_price_quotes SET fetched_at = CONVERT_TZ(fetched_at, 'UTC', 'America/Toronto') WHERE fetched_at IS NOT NULL");
        DB::statement("UPDATE product_price_quotes SET created_at = CONVERT_TZ(created_at, 'UTC', 'America/Toronto') WHERE created_at IS NOT NULL");
        DB::statement("UPDATE product_price_quotes SET updated_at = CONVERT_TZ(updated_at, 'UTC', 'America/Toronto') WHERE updated_at IS NOT NULL");

        DB::statement("UPDATE users SET email_verified_at = CONVERT_TZ(email_verified_at, 'UTC', 'America/Toronto') WHERE email_verified_at IS NOT NULL");
        DB::statement("UPDATE users SET created_at = CONVERT_TZ(created_at, 'UTC', 'America/Toronto') WHERE created_at IS NOT NULL");
        DB::statement("UPDATE users SET updated_at = CONVERT_TZ(updated_at, 'UTC', 'America/Toronto') WHERE updated_at IS NOT NULL");

        DB::statement("UPDATE password_reset_tokens SET created_at = CONVERT_TZ(created_at, 'UTC', 'America/Toronto') WHERE created_at IS NOT NULL");
        DB::statement("UPDATE failed_jobs SET failed_at = CONVERT_TZ(failed_at, 'UTC', 'America/Toronto') WHERE failed_at IS NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Best-effort reversal. If the up() migration ran, we can restore timestamps back to UTC.
        DB::statement("SET time_zone = 'America/Toronto'");

        DB::statement("UPDATE price_research_runs SET started_at = CONVERT_TZ(started_at, 'America/Toronto', 'UTC') WHERE started_at IS NOT NULL");
        DB::statement("UPDATE price_research_runs SET finished_at = CONVERT_TZ(finished_at, 'America/Toronto', 'UTC') WHERE finished_at IS NOT NULL");
        DB::statement("UPDATE price_research_runs SET created_at = CONVERT_TZ(created_at, 'America/Toronto', 'UTC') WHERE created_at IS NOT NULL");
        DB::statement("UPDATE price_research_runs SET updated_at = CONVERT_TZ(updated_at, 'America/Toronto', 'UTC') WHERE updated_at IS NOT NULL");

        DB::statement("UPDATE products SET created_at = CONVERT_TZ(created_at, 'America/Toronto', 'UTC') WHERE created_at IS NOT NULL");
        DB::statement("UPDATE products SET updated_at = CONVERT_TZ(updated_at, 'America/Toronto', 'UTC') WHERE updated_at IS NOT NULL");
        DB::statement("UPDATE products SET price_researched_at = CONVERT_TZ(price_researched_at, 'America/Toronto', 'UTC') WHERE price_researched_at IS NOT NULL");

        DB::statement("UPDATE product_price_quotes SET fetched_at = CONVERT_TZ(fetched_at, 'America/Toronto', 'UTC') WHERE fetched_at IS NOT NULL");
        DB::statement("UPDATE product_price_quotes SET created_at = CONVERT_TZ(created_at, 'America/Toronto', 'UTC') WHERE created_at IS NOT NULL");
        DB::statement("UPDATE product_price_quotes SET updated_at = CONVERT_TZ(updated_at, 'America/Toronto', 'UTC') WHERE updated_at IS NOT NULL");

        DB::statement("UPDATE users SET email_verified_at = CONVERT_TZ(email_verified_at, 'America/Toronto', 'UTC') WHERE email_verified_at IS NOT NULL");
        DB::statement("UPDATE users SET created_at = CONVERT_TZ(created_at, 'America/Toronto', 'UTC') WHERE created_at IS NOT NULL");
        DB::statement("UPDATE users SET updated_at = CONVERT_TZ(updated_at, 'America/Toronto', 'UTC') WHERE updated_at IS NOT NULL");

        DB::statement("UPDATE password_reset_tokens SET created_at = CONVERT_TZ(created_at, 'America/Toronto', 'UTC') WHERE created_at IS NOT NULL");
        DB::statement("UPDATE failed_jobs SET failed_at = CONVERT_TZ(failed_at, 'America/Toronto', 'UTC') WHERE failed_at IS NOT NULL");
    }
};
