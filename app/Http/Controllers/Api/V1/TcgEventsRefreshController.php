<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TcgEventsRefreshRequest;
use App\Services\TcgEvents\TcgEventsRefreshService;
use Illuminate\Http\JsonResponse;

final class TcgEventsRefreshController extends Controller
{
    public function __invoke(TcgEventsRefreshRequest $request, TcgEventsRefreshService $refresh): JsonResponse
    {
        $validated = $request->validated();

        $startDate = (string) $validated['start_date'];
        $streetAddress = isset($validated['street_address']) ? (string) $validated['street_address'] : 'montreal';
        $countryCode = isset($validated['country_code']) ? (string) $validated['country_code'] : 'CA';
        $prefCode = isset($validated['pref_code']) ? (string) $validated['pref_code'] : 'CA-QC';
        $gameTitleId = isset($validated['game_title_id']) ? (int) $validated['game_title_id'] : 16;
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 100;

        $result = $refresh->refreshBandaiEvents(
            startDate: $startDate,
            streetAddress: $streetAddress,
            countryCode: $countryCode,
            prefCode: $prefCode,
            gameTitleId: $gameTitleId,
            limit: $limit,
        );

        return response()->json([
            'data' => [
                'fetched_events' => $result->fetchedEvents,
                'upserted_events' => $result->upsertedEvents,
                'fetched_at' => $result->fetchedAt->toISOString(),
            ],
        ]);
    }
}
