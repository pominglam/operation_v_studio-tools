<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductTaxonomyApproveRequest;
use App\Http\Resources\Api\V1\ProductTaxonomyVerificationResource;
use App\Services\Products\Exceptions\ProductTaxonomyVerificationStateException;
use App\Services\Products\ProductTaxonomyApprovalService;
use Illuminate\Http\JsonResponse;

final class ProductTaxonomyApproveController extends Controller
{
    public function __construct(
        private readonly ProductTaxonomyApprovalService $approval,
    ) {}

    public function __invoke(
        ProductTaxonomyApproveRequest $request,
        string $id,
    ): ProductTaxonomyVerificationResource|JsonResponse {
        try {
            /** @var array<string, string|null> $values */
            $values = $request->validated('values');
            $verification = $this->approval->approve(
                $id,
                $values,
                (string) $request->validated('operator'),
                $request->validated('notes'),
            );

            return ProductTaxonomyVerificationResource::make($verification);
        } catch (ProductTaxonomyVerificationStateException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
