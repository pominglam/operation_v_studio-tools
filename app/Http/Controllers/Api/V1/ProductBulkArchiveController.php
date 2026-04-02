<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BulkArchiveProductsRequest;
use App\Services\Products\ProductBulkArchiveService;
use Illuminate\Http\JsonResponse;

final class ProductBulkArchiveController extends Controller
{
    public function __construct(
        private readonly ProductBulkArchiveService $archiver,
    ) {}

    public function __invoke(BulkArchiveProductsRequest $request): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('ids');
        $archived = (bool) ($request->validated('archived') ?? true);

        $updated = $this->archiver->setArchivedByUuids($ids, $archived);

        return response()->json([
            'updated' => $updated,
        ]);
    }
}

