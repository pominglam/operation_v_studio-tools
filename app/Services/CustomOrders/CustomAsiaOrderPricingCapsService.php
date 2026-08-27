<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\DAL\Maintenance\MaintenanceNoteRepository;
use App\Models\MaintenanceNote;
use App\Support\CustomOrders\CustomAsiaOrderPricingCaps;

final class CustomAsiaOrderPricingCapsService
{
    public const KEY = 'custom_asia_pricing_caps';

    public function __construct(
        private readonly MaintenanceNoteRepository $notes,
    ) {}

    /** @return array{merchandiser_commission_cap_cad: string, opv_margin_cap_cad: string} */
    public function getCaps(): array
    {
        $note = $this->notes->findByKey(self::KEY);
        $body = $note?->body;

        if (! is_string($body) || trim($body) === '') {
            return CustomAsiaOrderPricingCaps::defaults();
        }

        try {
            return CustomAsiaOrderPricingCaps::decodeStoredBody($body) ?? CustomAsiaOrderPricingCaps::defaults();
        } catch (\InvalidArgumentException) {
            return CustomAsiaOrderPricingCaps::defaults();
        }
    }

    public function isUsingDefaults(): bool
    {
        $note = $this->notes->findByKey(self::KEY);
        $body = $note?->body;

        return ! is_string($body) || trim($body) === '';
    }

    /**
     * @param  array{merchandiser_commission_cap_cad: string, opv_margin_cap_cad: string}  $caps
     */
    public function upsert(array $caps): MaintenanceNote
    {
        return $this->notes->upsert(self::KEY, CustomAsiaOrderPricingCaps::encode($caps));
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
        $defaults = CustomAsiaOrderPricingCaps::defaults();

        return [
            'merchandiser_commission_cap_cad' => $this->getCaps()['merchandiser_commission_cap_cad'],
            'opv_margin_cap_cad' => $this->getCaps()['opv_margin_cap_cad'],
            'default_merchandiser_commission_cap_cad' => $defaults['merchandiser_commission_cap_cad'],
            'default_opv_margin_cap_cad' => $defaults['opv_margin_cap_cad'],
            'is_default' => $this->isUsingDefaults(),
            'updated_at' => $note?->updated_at?->toIso8601String(),
        ];
    }
}
