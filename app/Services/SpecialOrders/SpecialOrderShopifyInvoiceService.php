<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\SpecialOrders\SpecialOrderRepository;
use App\Models\SpecialOrder;
use App\Services\Shopify\Admin\Orders\ShopifyDraftOrderWriter;
use App\Support\SpecialOrders\SpecialOrderContactMedia;
use App\Support\SpecialOrders\SpecialOrderCustomerPricing;
use App\Support\SpecialOrders\SpecialOrderShopifyInvoiceLineTitle;
use App\Support\SpecialOrders\SpecialOrderShopifyInvoiceNote;
use Illuminate\Support\Carbon;

final class SpecialOrderShopifyInvoiceService
{
    public function __construct(
        private readonly SpecialOrderRepository $orders,
        private readonly ShopifyDraftOrderWriter $draftOrders,
    ) {}

    public function createDepositInvoice(
        string $uuid,
        string $lineTitle,
        string $customerGid,
        bool $sendInvoice = true,
    ): SpecialOrder {
        $order = $this->orders->findByUuidOrFail($uuid);
        $this->assertCanCreateDepositInvoice($order);

        $amounts = $this->resolveAmounts($order);
        $baseTitle = $this->normalizeLineTitle($lineTitle, $order);
        $title = SpecialOrderShopifyInvoiceLineTitle::forDeposit($baseTitle);

        $draft = $this->draftOrders->createCustomLineDraftOrder(
            customerGid: $customerGid,
            lineTitle: $title,
            amountCad: $amounts['deposit'],
            note: SpecialOrderShopifyInvoiceNote::forDeposit(
                $order->uuid,
                $amounts['total'],
                $amounts['deposit'],
                $amounts['balance'],
                SpecialOrderContactMedia::label((string) $order->customer_contact_media),
                (string) $order->customer_contact_value,
                null,
            ),
            customAttributes: [
                ['key' => 'erp_order_uuid', 'value' => $order->uuid],
                ['key' => 'invoice_kind', 'value' => 'deposit'],
                ['key' => 'quoted_total_cad', 'value' => $amounts['total']],
                ['key' => 'balance_due_cad', 'value' => $amounts['balance']],
            ],
            tag: 'special-deposit',
            sendInvoice: $sendInvoice,
        );

        $sentAt = $sendInvoice ? Carbon::now('America/Toronto') : null;

        return $this->orders->update($order, [
            'shopify_customer_gid' => $customerGid,
            'shopify_deposit_draft_order_gid' => $draft['gid'],
            'shopify_deposit_draft_order_legacy_id' => $draft['legacy_id'],
            'shopify_deposit_draft_order_name' => $draft['name'],
            'shopify_deposit_invoice_url' => $draft['invoice_url'],
            'shopify_deposit_invoice_sent_at' => $sentAt,
        ]);
    }

    public function createBalanceInvoice(
        string $uuid,
        string $lineTitle,
        ?string $customerGid,
        bool $sendInvoice = true,
    ): SpecialOrder {
        $order = $this->orders->findByUuidOrFail($uuid);
        $this->assertCanCreateBalanceInvoice($order);

        $amounts = $this->resolveAmounts($order);
        $baseTitle = $this->normalizeLineTitle($lineTitle, $order);
        $title = SpecialOrderShopifyInvoiceLineTitle::forBalance($baseTitle);
        $customer = $customerGid ?: $order->shopify_customer_gid;
        if (! is_string($customer) || trim($customer) === '') {
            throw new \InvalidArgumentException('A Shopify customer is required for the balance invoice.');
        }

        $draft = $this->draftOrders->createCustomLineDraftOrder(
            customerGid: $customer,
            lineTitle: $title,
            amountCad: $amounts['balance'],
            note: SpecialOrderShopifyInvoiceNote::forBalance(
                $order->uuid,
                $amounts['total'],
                $amounts['deposit'],
                $amounts['balance'],
                $order->shopify_deposit_draft_order_name,
            ),
            customAttributes: [
                ['key' => 'erp_order_uuid', 'value' => $order->uuid],
                ['key' => 'invoice_kind', 'value' => 'balance'],
                ['key' => 'quoted_total_cad', 'value' => $amounts['total']],
                ['key' => 'deposit_paid_cad', 'value' => $amounts['deposit']],
                ['key' => 'deposit_draft_order_gid', 'value' => (string) $order->shopify_deposit_draft_order_gid],
            ],
            tag: 'special-balance',
            sendInvoice: $sendInvoice,
        );

        $sentAt = $sendInvoice ? Carbon::now('America/Toronto') : null;

        return $this->orders->update($order, [
            'shopify_customer_gid' => $customer,
            'shopify_balance_draft_order_gid' => $draft['gid'],
            'shopify_balance_draft_order_legacy_id' => $draft['legacy_id'],
            'shopify_balance_draft_order_name' => $draft['name'],
            'shopify_balance_invoice_url' => $draft['invoice_url'],
            'shopify_balance_invoice_sent_at' => $sentAt,
        ]);
    }

    public function clearDepositInvoice(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);
        $this->assertCanClearDepositInvoice($order);

        return $this->orders->update($order, [
            'shopify_deposit_draft_order_gid' => null,
            'shopify_deposit_draft_order_legacy_id' => null,
            'shopify_deposit_draft_order_name' => null,
            'shopify_deposit_invoice_url' => null,
            'shopify_deposit_invoice_sent_at' => null,
        ]);
    }

    public function clearBalanceInvoice(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);
        $this->assertCanClearBalanceInvoice($order);

        return $this->orders->update($order, [
            'shopify_balance_draft_order_gid' => null,
            'shopify_balance_draft_order_legacy_id' => null,
            'shopify_balance_draft_order_name' => null,
            'shopify_balance_invoice_url' => null,
            'shopify_balance_invoice_sent_at' => null,
        ]);
    }

    private function assertCanCreateDepositInvoice(SpecialOrder $order): void
    {
        if ($order->isRejected()) {
            throw new \InvalidArgumentException('Rejected orders cannot create Shopify invoices.');
        }

        if (! $order->isOfferLocked()) {
            throw new \InvalidArgumentException('Lock the customer offer before creating a Shopify invoice.');
        }

        if (! $order->isPriced()) {
            throw new \InvalidArgumentException('Customer price and deposit are required before creating a Shopify invoice.');
        }

        if ($order->shopify_deposit_draft_order_gid !== null) {
            throw new \InvalidArgumentException('A Shopify deposit invoice already exists for this order.');
        }

        if ($order->balance_received_at !== null) {
            throw new \InvalidArgumentException('Customer already paid in full — a deposit invoice is not needed.');
        }

        if ($order->deposit_received_at !== null) {
            throw new \InvalidArgumentException('Deposit already received — a Shopify deposit invoice is not needed.');
        }

        $amounts = $this->resolveAmounts($order);
        if ((float) $amounts['deposit'] <= 0) {
            throw new \InvalidArgumentException('This order has no deposit amount to invoice.');
        }
    }

    private function assertCanCreateBalanceInvoice(SpecialOrder $order): void
    {
        if ($order->isRejected()) {
            throw new \InvalidArgumentException('Rejected orders cannot create Shopify invoices.');
        }

        if ($order->shopify_deposit_draft_order_gid === null) {
            throw new \InvalidArgumentException('Create the deposit Shopify invoice before creating the balance invoice.');
        }

        if ($order->product_received_at === null) {
            throw new \InvalidArgumentException('Mark the product as in hand at OPV before creating the balance Shopify invoice.');
        }

        if ($order->shopify_balance_draft_order_gid !== null) {
            throw new \InvalidArgumentException('A Shopify balance invoice already exists for this order.');
        }

        if ($order->balance_received_at !== null) {
            throw new \InvalidArgumentException('Customer already paid in full — a balance invoice is not needed.');
        }

        $balance = SpecialOrderCustomerPricing::balance(
            is_string($order->customer_price_cad) ? $order->customer_price_cad : null,
            is_string($order->deposit_percent) ? number_format((float) $order->deposit_percent, 2, '.', '') : null,
            is_string($order->deposit_amount_override_cad) ? $order->deposit_amount_override_cad : null,
        );

        if ($balance === null || (float) $balance <= 0) {
            throw new \InvalidArgumentException('This order has no balance amount to invoice.');
        }
    }

    private function assertCanClearDepositInvoice(SpecialOrder $order): void
    {
        if ($order->shopify_deposit_draft_order_gid === null || $order->shopify_deposit_draft_order_gid === '') {
            throw new \InvalidArgumentException('This order has no Shopify deposit invoice record.');
        }

        if ($order->shopify_balance_draft_order_gid !== null && $order->shopify_balance_draft_order_gid !== '') {
            throw new \InvalidArgumentException('Remove the Shopify balance invoice record first.');
        }
    }

    private function assertCanClearBalanceInvoice(SpecialOrder $order): void
    {
        if ($order->shopify_balance_draft_order_gid === null || $order->shopify_balance_draft_order_gid === '') {
            throw new \InvalidArgumentException('This order has no Shopify balance invoice record.');
        }
    }

    /**
     * @return array{total: string, deposit: string, balance: string}
     */
    private function resolveAmounts(SpecialOrder $order): array
    {
        $depositPercent = is_string($order->deposit_percent)
            ? number_format((float) $order->deposit_percent, 2, '.', '')
            : null;

        $total = $order->customer_price_cad;
        $deposit = SpecialOrderCustomerPricing::depositAmount(
            is_string($total) ? $total : null,
            $depositPercent,
            is_string($order->deposit_amount_override_cad) ? $order->deposit_amount_override_cad : null,
        );
        $balance = SpecialOrderCustomerPricing::balance(
            is_string($total) ? $total : null,
            $depositPercent,
            is_string($order->deposit_amount_override_cad) ? $order->deposit_amount_override_cad : null,
        );

        if (! is_string($total) || ! is_string($deposit) || ! is_string($balance)) {
            throw new \InvalidArgumentException('Customer price and deposit are required before creating a Shopify invoice.');
        }

        if ((float) $deposit <= 0) {
            throw new \InvalidArgumentException('Deposit amount must be greater than zero.');
        }

        return [
            'total' => $total,
            'deposit' => $deposit,
            'balance' => $balance,
        ];
    }

    private function normalizeLineTitle(string $lineTitle, SpecialOrder $order): string
    {
        $lineTitle = trim($lineTitle);
        if ($lineTitle !== '') {
            return $lineTitle;
        }

        $productName = trim((string) $order->product_name);
        if ($productName !== '') {
            return $productName;
        }

        throw new \InvalidArgumentException('Line title or product name is required.');
    }
}
