<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShopifyCustomerStoreRequest;
use App\Services\Shopify\Admin\Customers\ShopifyCustomerCreateService;
use Illuminate\Http\JsonResponse;

final class ShopifyCustomerStoreController extends Controller
{
    public function __construct(
        private readonly ShopifyCustomerCreateService $customers,
    ) {}

    public function __invoke(ShopifyCustomerStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $customer = $this->customers->create(
                (string) $validated['email'],
                (string) $validated['first_name'],
                (string) $validated['last_name'],
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (ShopifyAdminConfigurationException|ShopifyGraphQlException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => $customer], 201);
    }
}
