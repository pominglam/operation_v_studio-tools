<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;
use App\Services\Shopify\Admin\Write\ShopifyWriteScopeGuard;
use Illuminate\Support\Facades\Log;

final class ShopifyDraftOrderWriter
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
    ) {}

    /**
     * @param  list<array{key: string, value: string}>  $customAttributes
     * @return array{
     *   gid: string,
     *   legacy_id: string|null,
     *   name: string|null,
     *   invoice_url: string|null
     * }
     */
    public function createCustomLineDraftOrder(
        string $customerGid,
        string $lineTitle,
        string $amountCad,
        string $note,
        array $customAttributes,
        string $tag,
        bool $sendInvoice,
    ): array {
        $this->scopeGuard->assertWriteDraftOrdersScope();

        Log::channel('shopify')->info('shopify.draft_order.create.start', [
            'customer_gid' => $customerGid,
            'amount_cad' => $amountCad,
            'tag' => $tag,
        ]);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::DRAFT_ORDER_CREATE, [
            'input' => [
                'presentmentCurrencyCode' => 'CAD',
                'purchasingEntity' => [
                    'customerId' => $customerGid,
                ],
                'note' => $note,
                'tags' => ['special-order', $tag],
                'customAttributes' => $customAttributes,
                'lineItems' => [[
                    'title' => $lineTitle,
                    'quantity' => 1,
                    'taxable' => true,
                    'requiresShipping' => true,
                    'originalUnitPriceWithCurrency' => [
                        'amount' => $amountCad,
                        'currencyCode' => 'CAD',
                    ],
                ]],
            ],
        ]);

        $draftOrder = $this->extractDraftOrderPayload($response['data']['draftOrderCreate'] ?? null);

        if ($sendInvoice) {
            $draftOrder = $this->sendInvoice($draftOrder['gid']);
        }

        Log::channel('shopify')->info('shopify.draft_order.create.finish', [
            'gid' => $draftOrder['gid'],
            'name' => $draftOrder['name'],
        ]);

        return $draftOrder;
    }

    /**
     * @return array{
     *   gid: string,
     *   legacy_id: string|null,
     *   name: string|null,
     *   invoice_url: string|null
     * }
     */
    public function sendInvoice(string $draftOrderGid): array
    {
        $this->scopeGuard->assertWriteDraftOrdersScope();

        $response = $this->client->query(ShopifyAdminGraphQlMutations::DRAFT_ORDER_INVOICE_SEND, [
            'id' => $draftOrderGid,
        ]);

        return $this->extractDraftOrderPayload($response['data']['draftOrderInvoiceSend'] ?? null);
    }

    /**
     * @return array{
     *   gid: string,
     *   legacy_id: string|null,
     *   name: string|null,
     *   invoice_url: string|null
     * }
     */
    private function extractDraftOrderPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new ShopifyGraphQlException('Shopify draft order mutation response missing payload.');
        }

        $errors = $payload['userErrors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $messages = array_values(array_filter(array_map(
                static fn (mixed $error): ?string => is_array($error) && is_string($error['message'] ?? null)
                    ? $error['message']
                    : null,
                $errors,
            )));

            throw new ShopifyGraphQlException($messages[0] ?? 'Shopify draft order mutation returned userErrors.');
        }

        $node = $payload['draftOrder'] ?? null;
        if (! is_array($node) || ! is_string($node['id'] ?? null) || $node['id'] === '') {
            throw new ShopifyGraphQlException('Shopify draft order mutation response missing draftOrder.id.');
        }

        return [
            'gid' => $node['id'],
            'legacy_id' => ShopifyGraphQlNodeParser::legacyString($node['legacyResourceId'] ?? null),
            'name' => isset($node['name']) && is_string($node['name']) ? $node['name'] : null,
            'invoice_url' => isset($node['invoiceUrl']) && is_string($node['invoiceUrl']) ? $node['invoiceUrl'] : null,
        ];
    }
}
