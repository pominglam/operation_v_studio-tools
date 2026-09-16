<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMarketingNoteWriteRequest;
use App\Http\Resources\Api\V1\StoreMarketingNoteResource;
use App\Services\StoreMarketing\StoreMarketingNoteService;
use Illuminate\Http\JsonResponse;

final class StoreMarketingNoteStoreController extends Controller
{
    public function __construct(
        private readonly StoreMarketingNoteService $notes,
    ) {}

    public function __invoke(StoreMarketingNoteWriteRequest $request): JsonResponse
    {
        return (new StoreMarketingNoteResource($this->notes->create($request->toWriteData())))
            ->response()
            ->setStatusCode(201);
    }
}
