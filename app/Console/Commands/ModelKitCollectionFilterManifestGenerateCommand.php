<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storefront\ModelKitCollectionFilterManifestGeneratorService;
use Illuminate\Console\Command;
use RuntimeException;

final class ModelKitCollectionFilterManifestGenerateCommand extends Command
{
    protected $signature = 'storefront:model-kit-collection-filter-manifest-generate
                            {--theme-path= : Path to ovs-shopify-theme root}';

    protected $description = 'Regenerate model-kit collection filter manifest files for ovs-shopify-theme';

    public function __construct(
        private readonly ModelKitCollectionFilterManifestGeneratorService $generator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $themePath = $this->option('theme-path');
            $result = $this->generator->generate(is_string($themePath) && $themePath !== '' ? $themePath : null);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Wrote {$result->handleCount} handles in {$result->durationMs} ms");
        $this->line("Theme root: {$result->themeRoot}");
        foreach ($result->writtenPaths as $path) {
            $this->line("  {$path}");
        }

        return self::SUCCESS;
    }
}
