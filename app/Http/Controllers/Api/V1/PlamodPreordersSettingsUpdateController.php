<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodPreordersSettingsUpdateRequest;
use App\Services\Plamod\PlamodPreorderSettingsService;
use Illuminate\Http\JsonResponse;

final class PlamodPreordersSettingsUpdateController extends Controller
{
    public function __invoke(
        PlamodPreordersSettingsUpdateRequest $request,
        PlamodPreorderSettingsService $settings,
    ): JsonResponse {
        $validated = $request->validated();
        /** @var array<int, string> $categories */
        $categories = $validated['excluded_categories'];

        return response()->json(['data' => $settings->save($categories)]);
    }
}
