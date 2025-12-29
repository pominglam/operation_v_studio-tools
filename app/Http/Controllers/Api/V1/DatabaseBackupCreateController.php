<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DatabaseBackupCreateRequest;
use App\Services\Maintenance\DatabaseBackupManager;
use Illuminate\Http\JsonResponse;

final class DatabaseBackupCreateController extends Controller
{
    public function __invoke(DatabaseBackupCreateRequest $request, DatabaseBackupManager $service): JsonResponse
    {
        /** @var string|null $description */
        $description = $request->validated('description');
        /** @var string|null $createdBy */
        $createdBy = $request->validated('created_by');

        $backup = $service->create(
            description: $description ?? '',
            createdBy: $createdBy ?? 'manual',
        );

        return response()->json([
            'data' => [
                'uuid' => $backup->uuid,
                'filename' => $backup->filename,
                'description' => $backup->description,
                'created_by' => $backup->created_by,
                'created_at' => optional($backup->created_at)->toISOString(),
            ],
        ], 201);
    }
}


