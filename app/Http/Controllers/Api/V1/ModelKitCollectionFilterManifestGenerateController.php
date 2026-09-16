<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Storefront\ModelKitCollectionFilterManifestGeneratorService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class ModelKitCollectionFilterManifestGenerateController extends Controller
{
    public function __construct(
        private readonly ModelKitCollectionFilterManifestGeneratorService $generator,
    ) {}

    public function __invoke(): JsonResponse
    {
        try {
            $result = $this->generator->generate();
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'handle_count' => $result->handleCount,
            'duration_ms' => $result->durationMs,
            'theme_root' => $result->themeRoot,
            'written_paths' => $result->writtenPaths,
        ]);
    }
}
