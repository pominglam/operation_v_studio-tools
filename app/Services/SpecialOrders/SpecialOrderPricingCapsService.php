<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\Maintenance\MaintenanceNoteRepository;
use App\Models\MaintenanceNote;
use App\Support\SpecialOrders\SpecialOrderPricingCaps;

final class SpecialOrderPricingCapsService
{
    public const KEY = 'special_order_pricing_caps';

    public function __construct(
        private readonly MaintenanceNoteRepository $notes,
    ) {}

    /**
     * @return array{
     *   merchandiser_commission_cap_cad: string,
     *   opv_margin_cap_cad: string,
     *   default_shipping_cost_amount: string,
     *   default_shipping_cost_currency: string
     * }
     */
    public function getCaps(): array
    {
        $note = $this->notes->findByKey(self::KEY);
        $body = $note?->body;

        if (! is_string($body) || trim($body) === '') {
            return SpecialOrderPricingCaps::defaults();
        }

        try {
            return SpecialOrderPricingCaps::decodeStoredBody($body) ?? SpecialOrderPricingCaps::defaults();
        } catch (\InvalidArgumentException) {
            return SpecialOrderPricingCaps::defaults();
        }
    }

    public function isUsingDefaults(): bool
    {
        $note = $this->notes->findByKey(self::KEY);
        $body = $note?->body;

        return ! is_string($body) || trim($body) === '';
    }

    /**
     * @param  array{
     *   merchandiser_commission_cap_cad: string,
     *   opv_margin_cap_cad: string,
     *   default_shipping_cost_amount: string,
     *   default_shipping_cost_currency: string
     * }  $caps
     */
    public function upsert(array $caps): MaintenanceNote
    {
        return $this->notes->upsert(self::KEY, SpecialOrderPricingCaps::encode($caps));
    }

    public function resetToDefaults(): void
    {
        $note = $this->notes->findByKey(self::KEY);
        if ($note !== null) {
            $note->delete();
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $note = $this->notes->findByKey(self::KEY);
        $defaults = SpecialOrderPricingCaps::defaults();

        $caps = $this->getCaps();

        return [
            'merchandiser_commission_cap_cad' => $caps['merchandiser_commission_cap_cad'],
            'opv_margin_cap_cad' => $caps['opv_margin_cap_cad'],
            'default_shipping_cost_amount' => $caps['default_shipping_cost_amount'],
            'default_shipping_cost_currency' => $caps['default_shipping_cost_currency'],
            'default_shipping_cost_per_kg_cny' => $caps['default_shipping_cost_per_kg_cny'],
            'default_merchandiser_commission_cap_cad' => $defaults['merchandiser_commission_cap_cad'],
            'default_opv_margin_cap_cad' => $defaults['opv_margin_cap_cad'],
            'default_default_shipping_cost_amount' => $defaults['default_shipping_cost_amount'],
            'default_default_shipping_cost_currency' => $defaults['default_shipping_cost_currency'],
            'default_default_shipping_cost_per_kg_cny' => $defaults['default_shipping_cost_per_kg_cny'],
            'is_default' => $this->isUsingDefaults(),
            'updated_at' => $note?->updated_at?->toIso8601String(),
        ];
    }
}
