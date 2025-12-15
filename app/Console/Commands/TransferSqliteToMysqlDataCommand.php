<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DatabaseTransfer\SqliteToMysqlTransferService;
use Illuminate\Console\Command;

final class TransferSqliteToMysqlDataCommand extends Command
{
    protected $signature = 'db:transfer-sqlite-to-mysql
        {--truncate : Truncate destination tables before copying}
        {--force : Allow running even if destination has data}
        {--yes : Do not prompt; assume yes}';

    protected $description = 'One-time transfer of local SQLite data (database/database.sqlite) into MySQL, preserving IDs.';

    public function handle(SqliteToMysqlTransferService $service): int
    {
        $truncate = (bool) $this->option('truncate');
        $force = (bool) $this->option('force');
        $yes = (bool) $this->option('yes');

        $this->info('Inspecting source (SQLite) and destination (MySQL) counts...');

        try {
            $inspect = $service->inspect();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['table', 'sqlite', 'mysql_before'],
            collect(array_keys($inspect['sqlite_counts']))->map(function (string $table) use ($inspect): array {
                return [$table, $inspect['sqlite_counts'][$table], $inspect['mysql_counts'][$table]];
            })->all(),
        );

        $mysqlHasData = collect($inspect['mysql_counts'])->sum() > 0;
        if ($mysqlHasData && ! $force && ! $truncate) {
            $this->warn('MySQL already has data. Re-run with --truncate (wipe & re-copy) or --force (append, not recommended).');

            return self::FAILURE;
        }

        if ($truncate) {
            $this->warn('This will TRUNCATE MySQL tables (products, price_research_runs, product_price_quotes) before copying.');
        }

        if (! $yes && ! $this->confirm('Proceed with transfer?', false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = $service->transfer($truncate);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Transfer complete.');
        $this->table(
            ['table', 'mysql_after'],
            collect(array_keys($result['mysql_counts_after']))->map(function (string $table) use ($result): array {
                return [$table, $result['mysql_counts_after'][$table]];
            })->all(),
        );

        return self::SUCCESS;
    }
}
