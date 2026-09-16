<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StoreMarketing\Exceptions\StoreMarketingNoteNotFoundException;
use App\Services\StoreMarketing\StoreMarketingNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class StoreMarketingNoteDeleteController extends Controller
{
    public function __construct(
        private readonly StoreMarketingNoteService $notes,
    ) {}

    public function __invoke(string $noteId): JsonResponse|Response
    {
        try {
            $this->notes->delete($noteId);
        } catch (StoreMarketingNoteNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->noContent();
    }
}
