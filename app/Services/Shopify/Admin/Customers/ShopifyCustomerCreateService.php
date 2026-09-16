<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Customers;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Shopify\ShopifyCustomer;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;
use App\Services\Shopify\Admin\Write\ShopifyWriteScopeGuard;
use Illuminate\Support\Facades\Log;

final class ShopifyCustomerCreateService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
    ) {}

    /**
     * @return array{gid: string, display_name: string|null, email: string|null, legacy_numeric_id: string|null}
     */
    public function create(string $email, string $firstName, string $lastName): array
    {
        $this->scopeGuard->assertWriteCustomersScope();

        $email = trim($email);
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        if ($email === '') {
            throw new \InvalidArgumentException('Customer email is required.');
        }

        if ($firstName === '' && $lastName === '') {
            throw new \InvalidArgumentException('Customer first or last name is required.');
        }

        Log::channel('shopify')->info('shopify.customer.create.start', [
            'email' => $email,
        ]);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::CUSTOMER_CREATE, [
            'input' => [
                'email' => $email,
                'firstName' => $firstName !== '' ? $firstName : null,
                'lastName' => $lastName !== '' ? $lastName : null,
            ],
        ]);

        $payload = $response['data']['customerCreate'] ?? null;
        if (! is_array($payload)) {
            throw new ShopifyGraphQlException('Shopify customerCreate response missing data.customerCreate.');
        }

        $errors = $payload['userErrors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $messages = array_values(array_filter(array_map(
                static fn (mixed $error): ?string => is_array($error) && is_string($error['message'] ?? null)
                    ? $error['message']
                    : null,
                $errors,
            )));

            throw new ShopifyGraphQlException($messages[0] ?? 'Shopify customerCreate returned userErrors.');
        }

        $node = $payload['customer'] ?? null;
        if (! is_array($node) || ! is_string($node['id'] ?? null) || $node['id'] === '') {
            throw new ShopifyGraphQlException('Shopify customerCreate response missing customer.id.');
        }

        $emailAddress = null;
        $dea = $node['defaultEmailAddress'] ?? null;
        if (is_array($dea) && is_string($dea['emailAddress'] ?? null)) {
            $emailAddress = $dea['emailAddress'];
        }

        ShopifyCustomer::query()->updateOrCreate(
            ['gid' => $node['id']],
            [
                'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($node['legacyResourceId'] ?? null),
                'display_name' => isset($node['displayName']) && is_string($node['displayName'])
                    ? $node['displayName']
                    : trim($firstName.' '.$lastName),
                'email' => $emailAddress ?? $email,
                'customer_created_at' => now(),
                'graphql_updated_at' => now(),
                'payload_json' => $node,
            ],
        );

        Log::channel('shopify')->info('shopify.customer.create.finish', [
            'gid' => $node['id'],
        ]);

        return [
            'gid' => $node['id'],
            'display_name' => isset($node['displayName']) && is_string($node['displayName'])
                ? $node['displayName']
                : trim($firstName.' '.$lastName),
            'email' => $emailAddress ?? $email,
            'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($node['legacyResourceId'] ?? null),
        ];
    }
}
