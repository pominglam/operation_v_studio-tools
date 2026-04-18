<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DAL\Maintenance\DatabaseBackupRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DatabaseBackupRestoreRequest;
use App\Services\Maintenance\DatabaseRestore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class DatabaseBackupRestoreController extends Controller
{
    public function __invoke(
        DatabaseBackupRestoreRequest $request,
        DatabaseBackupRepository $backups,
        DatabaseRestore $restore,
    ): JsonResponse {
        // Restoring can be slow (DB + images).
        @set_time_limit(0);
        @ignore_user_abort(true);

        /** @var string $uuid */
        $uuid = $request->validated('backup_uuid');

        try {
            $backup = $backups->findByUuidOrFail($uuid);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Backup not found.'], 404);
        }

        try {
            $restore->restore($backup);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json(['ok' => true]);
    }
}
