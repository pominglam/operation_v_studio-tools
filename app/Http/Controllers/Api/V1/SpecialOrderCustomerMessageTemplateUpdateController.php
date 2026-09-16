<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SpecialOrderCustomerMessageTemplateUpdateRequest;
use App\Services\SpecialOrders\SpecialOrderCustomerMessageTemplateService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderCustomerMessageTemplateUpdateController extends Controller
{
    public function __construct(
        private readonly SpecialOrderCustomerMessageTemplateService $templates,
    ) {}

    public function __invoke(SpecialOrderCustomerMessageTemplateUpdateRequest $request): JsonResponse
    {
        if ($request->boolean('reset')) {
            $this->templates->resetToDefault();
        } else {
            $this->templates->upsert((string) $request->validated('body'));
        }

        return response()->json([
            'data' => $this->templates->toArray(),
        ]);
    }
}
