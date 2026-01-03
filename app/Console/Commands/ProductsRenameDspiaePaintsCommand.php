<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\DspiaePaintRenameService;
use Illuminate\Console\Command;

final class ProductsRenameDspiaePaintsCommand extends Command
{
    protected $signature = 'products:rename-dspiae-paints
        {--apply : Actually update product names (default is dry-run)}
        {--preview=25 : Preview row limit}';

    protected $description = 'Renames DSPIAE Base Color paints to: "{SKU} - {Paint Name} - DSPIAE - {volume}".';

    public function handle(DspiaePaintRenameService $service): int
    {
        $apply = (bool) $this->option('apply');
        $preview = (int) $this->option('preview');
        if ($preview < 0) {
            $preview = 0;
        }

        $this->info($apply ? 'Applying DSPIAE paint renames…' : 'Dry-run (no changes will be saved)…');

        try {
            $result = $service->rename($apply, $preview);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

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

