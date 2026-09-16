<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Storefront\ModelKitStorefrontIndexWriteException;
use App\Services\Storefront\ModelKitStorefrontIndexRebuildService;
use Illuminate\Console\Command;

final class ModelKitStorefrontIndexRebuildCommand extends Command
{
    protected $signature = 'storefront:model-kit-index-rebuild';

    protected $description = 'Recreate the Shopify theme JSON cache used by model-kit collection filters';

    public function __construct(
        private readonly ModelKitStorefrontIndexRebuildService $rebuild,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->rebuild->rebuild();
        } catch (ModelKitStorefrontIndexWriteException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Wrote {$result->productCount} model kits ({$result->bytes} bytes) in {$result->writePasses} pass(es)");
        $this->line('Generated at: '.$result->generatedAt);
        foreach ($result->themeIds as $themeId) {
            $this->line('  theme '.$themeId);
        }

        return self::SUCCESS;
    }
}
