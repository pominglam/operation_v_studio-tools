<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TcgEventsIndexRequest;
use App\Http\Resources\Api\V1\TcgEventResource;
use App\Services\TcgEvents\TcgEventsQueryService;
use Illuminate\Http\JsonResponse;

final class TcgEventsIndexController extends Controller
{
    public function __invoke(TcgEventsIndexRequest $request, TcgEventsQueryService $query): JsonResponse
    {
        $validated = $request->validated();

        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 50;
        $search = isset($validated['search']) ? (string) $validated['search'] : null;
        $startDate = isset($validated['start_date']) ? (string) $validated['start_date'] : null;
        $status = isset($validated['status']) ? (string) $validated['status'] : null;
        $format = isset($validated['format']) ? (string) $validated['format'] : null;
        $hideZeroApplicants = isset($validated['hide_zero_applicants']) ? (bool) $validated['hide_zero_applicants'] : false;

        $result = $query->paginate(
            perPage: $perPage,
            search: $search,
            startDate: $startDate,
            status: $status,
            format: $format,
            hideZeroApplicants: $hideZeroApplicants,
        );

        /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator */
        $paginator = $result['paginator'];

        return TcgEventResource::collection($paginator)->additional([
            'meta' => [
                'latest_fetched_at' => $result['latestFetchedAt']?->toISOString(),
            ],
        ])->response();
    }
}
