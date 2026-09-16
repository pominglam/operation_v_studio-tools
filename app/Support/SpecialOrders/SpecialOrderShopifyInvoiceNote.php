<?php

declare(strict_types=1);

namespace App\Support\SpecialOrders;

final class SpecialOrderShopifyInvoiceNote
{
    public static function forDeposit(
        string $orderUuid,
        string $customerPriceCad,
        string $depositAmountCad,
        string $balanceCad,
        string $contactMediaLabel,
        string $contactValue,
        ?string $depositDraftName,
    ): string {
        $lines = [
            'Custom Asia order (ERP '.$orderUuid.').',
            'Total quoted: $'.$customerPriceCad.' CAD.',
            'This invoice: deposit $'.$depositAmountCad.' CAD.',
            'Balance $'.$balanceCad.' CAD due before fulfillment (separate invoice).',
            'Customer contact: '.$contactMediaLabel.' '.$contactValue.'.',
        ];

        if ($depositDraftName !== null && $depositDraftName !== '') {
            $lines[] = 'Deposit draft: '.$depositDraftName.'.';
        }

        return implode("\n", $lines);
    }

    public static function forBalance(
        string $orderUuid,
        string $customerPriceCad,
        string $depositAmountCad,
        string $balanceCad,
        ?string $depositDraftName,
    ): string {
        $lines = [
            'Custom Asia order (ERP '.$orderUuid.') — balance invoice.',
            'Total quoted: $'.$customerPriceCad.' CAD.',
            'Deposit paid: $'.$depositAmountCad.' CAD.',
            'This invoice: balance $'.$balanceCad.' CAD due before fulfillment.',
        ];

        if ($depositDraftName !== null && $depositDraftName !== '') {
            $lines[] = 'Related deposit draft: '.$depositDraftName.'.';
        }

        return implode("\n", $lines);
    }
}
