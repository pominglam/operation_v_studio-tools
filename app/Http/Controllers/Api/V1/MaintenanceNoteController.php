<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Maintenance\MaintenanceNoteService;
use Illuminate\Http\JsonResponse;

final class MaintenanceNoteController extends Controller
{
    public function __construct(
        private readonly MaintenanceNoteService $notes,
    ) {}

    public function __invoke(): JsonResponse
    {
        $note = $this->notes->getDefault();

        return response()->json([
            'data' => [
                'key' => MaintenanceNoteService::DEFAULT_KEY,
                'body' => $note?->body,
                'updated_at' => optional($note?->updated_at)->toISOString(),
            ],
        ]);
    }
}
