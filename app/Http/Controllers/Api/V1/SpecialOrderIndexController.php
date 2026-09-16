<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SpecialOrderIndexRequest;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderQueryService;
use App\Support\SpecialOrders\SpecialOrderIndexSort;
use App\Support\SpecialOrders\SpecialOrderLifecycleStatus;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SpecialOrderIndexController extends Controller
{
    public function __construct(
        private readonly SpecialOrderQueryService $orders,
    ) {}

    public function __invoke(SpecialOrderIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $perPage = max(1, min((int) ($validated['per_page'] ?? 50), 200));
        $contactMedia = self::stringList($validated['contact_media'] ?? null);
        $workflowStatuses = self::stringList($validated['workflow_status'] ?? null);
        $search = isset($validated['search']) ? (string) $validated['search'] : null;

        $paginator = $this->orders->paginate(
            $perPage,
            SpecialOrderIndexSort::normalize((string) ($validated['sort_by'] ?? SpecialOrderIndexSort::DEFAULT)),
            SpecialOrderIndexSort::normalizeDir((string) ($validated['sort_dir'] ?? 'desc')),
            $search,
            $contactMedia,
            isset($validated['quote_status']) ? (string) $validated['quote_status'] : null,
            isset($validated['pricing_status']) ? (string) $validated['pricing_status'] : null,
            SpecialOrderLifecycleStatus::normalize(
                isset($validated['lifecycle_status']) ? (string) $validated['lifecycle_status'] : null,
            ),
            $workflowStatuses,
        );

        return SpecialOrderResource::collection($paginator)->additional([
            'workflow_status_counts' => $this->orders->workflowStatusCounts($search, $contactMedia),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            $t = trim((string) $value);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }
}
