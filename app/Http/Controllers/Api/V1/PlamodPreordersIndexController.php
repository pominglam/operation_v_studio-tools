<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodPreordersIndexRequest;
use App\Http\Resources\Api\V1\PlamodPreorderResource;
use App\Models\PlamodPreorder;
use App\Services\Plamod\PlamodPreorderQueryService;
use Illuminate\Http\JsonResponse;

final class PlamodPreordersIndexController extends Controller
{
    public function __invoke(PlamodPreordersIndexRequest $request, PlamodPreorderQueryService $query): JsonResponse
    {
        $validated = $request->validated();
        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 50;
        $search = isset($validated['search']) ? (string) $validated['search'] : null;
        $newOnly = isset($validated['new_only']) ? (bool) $validated['new_only'] : null;

        $paginator = $query->paginate($perPage, $newOnly, $search);
        $catalogSkus = $query->catalogSkus();
        $catalogSet = array_flip($catalogSkus);

        $paginator->getCollection()->transform(function (PlamodPreorder $row) use ($catalogSet): PlamodPreorder {
            $row->setAttribute('_is_new', ! isset($catalogSet[trim((string) $row->sku)]));

            return $row;
        });

        return PlamodPreorderResource::collection($paginator)->additional([
            'meta' => [
                'categories' => $query->listCategories(),
            ],
        ])->response();
    }
}
