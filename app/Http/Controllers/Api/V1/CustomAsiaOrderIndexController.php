<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomAsiaOrderIndexRequest;
use App\Http\Resources\Api\V1\CustomAsiaOrderResource;
use App\Services\CustomOrders\CustomAsiaOrderQueryService;
use App\Support\CustomOrders\CustomAsiaOrderIndexSort;
use App\Support\CustomOrders\CustomAsiaOrderLifecycleStatus;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CustomAsiaOrderIndexController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderQueryService $orders,
    ) {}

    public function __invoke(CustomAsiaOrderIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $perPage = max(1, min((int) ($validated['per_page'] ?? 50), 200));

        /** @var array<int, string> $contactMedia */
        $contactMedia = [];
        if (isset($validated['contact_media']) && is_array($validated['contact_media'])) {
            foreach ($validated['contact_media'] as $media) {
                $t = trim((string) $media);
                if ($t !== '') {
                    $contactMedia[] = $t;
                }
            }
        }

        return CustomAsiaOrderResource::collection(
            $this->orders->paginate(
                $perPage,
                CustomAsiaOrderIndexSort::normalize((string) ($validated['sort_by'] ?? CustomAsiaOrderIndexSort::DEFAULT)),
                CustomAsiaOrderIndexSort::normalizeDir((string) ($validated['sort_dir'] ?? 'desc')),
                isset($validated['search']) ? (string) $validated['search'] : null,
                array_values(array_unique($contactMedia)),
                isset($validated['quote_status']) ? (string) $validated['quote_status'] : null,
                isset($validated['pricing_status']) ? (string) $validated['pricing_status'] : null,
                CustomAsiaOrderLifecycleStatus::normalize(
                    isset($validated['lifecycle_status']) ? (string) $validated['lifecycle_status'] : null,
                ),
            ),
        );
    }
}
