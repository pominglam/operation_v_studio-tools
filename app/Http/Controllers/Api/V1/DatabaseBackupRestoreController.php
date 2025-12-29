<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DAL\Maintenance\DatabaseBackupRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DatabaseBackupRestoreRequest;
use App\Services\Maintenance\DatabaseRestore;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class DatabaseBackupRestoreController extends Controller
{
    public function __invoke(
        DatabaseBackupRestoreRequest $request,
        DatabaseBackupRepository $backups,
        DatabaseRestore $restore,
    ): JsonResponse {
        /** @var string $uuid */
        $uuid = $request->validated('backup_uuid');

        try {
            $backup = $backups->findByUuidOrFail($uuid);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Backup not found.'], 404);
        }

        $restore->restore($backup);

        return response()->json(['ok' => true]);
    }
}


