<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMarketingNoteWriteRequest;
use App\Http\Resources\Api\V1\StoreMarketingNoteResource;
use App\Services\StoreMarketing\Exceptions\StoreMarketingNoteNotFoundException;
use App\Services\StoreMarketing\StoreMarketingNoteService;
use Illuminate\Http\JsonResponse;

final class StoreMarketingNoteUpdateController extends Controller
{
    public function __construct(
        private readonly StoreMarketingNoteService $notes,
    ) {}

    public function __invoke(string $noteId, StoreMarketingNoteWriteRequest $request): JsonResponse|StoreMarketingNoteResource
    {
        try {
            return new StoreMarketingNoteResource($this->notes->update($noteId, $request->toWriteData()));
        } catch (StoreMarketingNoteNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
    }
}
