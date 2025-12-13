<?php

declare(strict_types=1);

namespace App\Services\DatabaseTransfer;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class SqliteToMysqlTransferService
{
    private readonly Connection $mysql;

    public function __construct(DatabaseManager $db)
    {
        /** @var Connection $conn */
        $conn = $db->connection();
        $this->mysql = $conn;
    }

    /**
     * @return array{sqlite_counts: array<string,int>, mysql_counts_before: array<string,int>, mysql_counts_after: array<string,int>}
     */
    public function transfer(bool $truncateDestination): array
    {
        $this->assertMysqlDestination();

        $sqlite = $this->sqliteConnection();

        $tables = ['products', 'price_research_runs', 'product_price_quotes'];

        $sqliteCounts = $this->counts($sqlite, $tables);
        $mysqlCountsBefore = $this->counts($this->mysql, $tables);

        // TRUNCATE in MySQL causes an implicit commit, so it can't run inside a transaction.
        if ($truncateDestination) {
            $this->truncateInDependencyOrder();
        }

        $this->mysql->transaction(function () use ($sqlite): void {
            $this->copyTablePreservingIds($sqlite, 'products');
            $this->copyTablePreservingIds($sqlite, 'price_research_runs');
            $this->copyTablePreservingIds($sqlite, 'product_price_quotes');
        });

        $mysqlCountsAfter = $this->counts($this->mysql, $tables);

        return [
            'sqlite_counts' => $sqliteCounts,
            'mysql_counts_before' => $mysqlCountsBefore,
            'mysql_counts_after' => $mysqlCountsAfter,
        ];
    }

    /**
     * @return array{sqlite_counts: array<string,int>, mysql_counts: array<string,int>}
     */
    public function inspect(): array
    {
        $this->assertMysqlDestination();

        $sqlite = $this->sqliteConnection();
        $tables = ['products', 'price_research_runs', 'product_price_quotes'];

        return [
            'sqlite_counts' => $this->counts($sqlite, $tables),
            'mysql_counts' => $this->counts($this->mysql, $tables),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function counts(Connection $connection, array $tables): array
    {
        $out = [];

        foreach ($tables as $table) {
            /** @var int $count */
            $count = (int) $connection->table($table)->count();
            $out[$table] = $count;
        }

        return $out;
    }

    private function sqliteConnection(): Connection
    {
        $sqlitePath = database_path('database.sqlite');
        if (! File::exists($sqlitePath)) {
            throw new \RuntimeException("SQLite file not found at {$sqlitePath}");
        }

        $name = 'sqlite_transfer';
        config([
            "database.connections.{$name}" => [
                'driver' => 'sqlite',
                'database' => $sqlitePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        return DB::connection($name);
    }

    private function truncateInDependencyOrder(): void
    {
        // product_price_quotes has FK -> products
        $this->mysql->statement('SET FOREIGN_KEY_CHECKS=0;');
        try {
            $this->mysql->table('product_price_quotes')->truncate();
            $this->mysql->table('price_research_runs')->truncate();
            $this->mysql->table('products')->truncate();
        } finally {
            $this->mysql->statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    private function copyTablePreservingIds(Connection $sqlite, string $table): void
    {
        $sqlite->table($table)
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($table): void {
                if ($rows->isEmpty()) {
                    return;
                }

                $payload = $rows->map(static fn ($row): array => (array) $row)->all();
                $this->mysql->table($table)->insert($payload);
            });
    }

    private function assertMysqlDestination(): void
    {
        if ($this->mysql->getDriverName() !== 'mysql') {
            $driver = $this->mysql->getDriverName();
            throw new \RuntimeException("Destination connection must be mysql; got {$driver}");
        }
    }
}


