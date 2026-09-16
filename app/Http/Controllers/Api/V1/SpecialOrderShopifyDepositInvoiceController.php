<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SpecialOrderShopifyInvoiceRequest;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\Shopify\Admin\Customers\ShopifyCustomerCreateService;
use App\Services\SpecialOrders\SpecialOrderShopifyInvoiceService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderShopifyDepositInvoiceController extends Controller
{
    public function __construct(
        private readonly SpecialOrderShopifyInvoiceService $invoices,
        private readonly ShopifyCustomerCreateService $customers,
    ) {}

    public function __invoke(string $id, SpecialOrderShopifyInvoiceRequest $request): JsonResponse|SpecialOrderResource
    {
        $validated = $request->validated();

        try {
            $customerGid = $this->resolveCustomerGid($validated);
            $order = $this->invoices->createDepositInvoice(
                $id,
                (string) ($validated['line_title'] ?? ''),
                $customerGid,
                (bool) ($validated['send_invoice'] ?? true),
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (ShopifyAdminConfigurationException|ShopifyGraphQlException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return SpecialOrderResource::make($order);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveCustomerGid(array $validated): string
    {
        $gid = isset($validated['shopify_customer_gid']) ? trim((string) $validated['shopify_customer_gid']) : '';
        if ($gid !== '') {
            return $gid;
        }

        $email = isset($validated['customer_email']) ? trim((string) $validated['customer_email']) : '';
        $firstName = isset($validated['customer_first_name']) ? trim((string) $validated['customer_first_name']) : '';
        $lastName = isset($validated['customer_last_name']) ? trim((string) $validated['customer_last_name']) : '';

        if ($email === '') {
            throw new \InvalidArgumentException('Select an existing Shopify customer or provide email and name to create one.');
        }

        $created = $this->customers->create($email, $firstName, $lastName);

        return $created['gid'];
    }
}
