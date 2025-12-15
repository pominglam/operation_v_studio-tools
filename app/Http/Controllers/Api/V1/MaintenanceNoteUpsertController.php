<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MaintenanceNoteUpsertRequest;
use App\Services\Maintenance\MaintenanceNoteService;
use Illuminate\Http\JsonResponse;

final class MaintenanceNoteUpsertController extends Controller
{
    public function __construct(
        private readonly MaintenanceNoteService $notes,
    ) {}

    public function __invoke(MaintenanceNoteUpsertRequest $request): JsonResponse
    {
        /** @var string|null $raw */
        $raw = $request->validated('body');
        $body = $raw === null ? null : trim($raw);
        if ($body === '') {
            $body = null;
        }

        $note = $this->notes->upsertDefault($body);

        return response()->json([
            'data' => [
                'key' => $note->key,
                'body' => $note->body,
                'updated_at' => optional($note->updated_at)->toISOString(),
            ],
        ]);
    }
}
