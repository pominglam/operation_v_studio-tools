<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Maintenance\DatabaseBackupManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseBackupIndexController extends Controller
{
    public function __invoke(Request $request, DatabaseBackupManager $service): JsonResponse
    {
        $limit = (int) ($request->query('limit') ?? 100);

        $items = array_map(static function ($b): array {
            return [
                'uuid' => $b->uuid,
                'driver' => $b->driver,
                'filename' => $b->filename,
                'description' => $b->description,
                'created_by' => $b->created_by,
                'size_bytes' => $b->size_bytes,
                'created_at' => optional($b->created_at)->toISOString(),
            ];
        }, $service->listRecent($limit));

        return response()->json(['data' => $items]);
    }
}
