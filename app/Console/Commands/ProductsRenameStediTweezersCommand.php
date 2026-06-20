<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\StediTweezerRenameService;
use Illuminate\Console\Command;

final class ProductsRenameStediTweezersCommand extends Command
{
    protected $signature = 'products:rename-stedi-tweezers
        {--apply : Actually update product names (default is dry-run)}
        {--preview=25 : Preview row limit}';

    protected $description = 'Renames Stedi tweezer SKUs to Ultra-Precision / Thick-Wall titles (Option A).';

    public function handle(StediTweezerRenameService $service): int
    {
        $apply = (bool) $this->option('apply');
        $preview = (int) $this->option('preview');
        if ($preview < 0) {
            $preview = 0;
        }

        $this->info($apply ? 'Applying Stedi tweezer renames…' : 'Dry-run (no changes will be saved)…');

        $result = $service->rename($apply, $preview);

        $this->line('');
        $this->info("Matched: {$result['matched']}");
        $this->info(($apply ? 'Updated' : 'Would update').": {$result['changed']}");

        /** @var array<int, array{sku:string, old:string, new:string}> $rows */
        $rows = $result['preview'];
        if ($rows !== []) {
            $this->line('');
            $this->table(['SKU', 'Old name', 'New name'], array_map(static fn (array $r): array => [
                $r['sku'],
                $r['old'],
                $r['new'],
            ], $rows));
        }

        return self::SUCCESS;
    }
}
