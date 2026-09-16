<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMarketingNoteIndexRequest;
use App\Http\Resources\Api\V1\StoreMarketingNoteResource;
use App\Services\StoreMarketing\StoreMarketingNoteService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class StoreMarketingNoteIndexController extends Controller
{
    public function __construct(
        private readonly StoreMarketingNoteService $notes,
    ) {}

    public function __invoke(StoreMarketingNoteIndexRequest $request): AnonymousResourceCollection
    {
        $search = $request->validated('search');

        return StoreMarketingNoteResource::collection(
            $this->notes->list(is_string($search) ? $search : null),
        );
    }
}
