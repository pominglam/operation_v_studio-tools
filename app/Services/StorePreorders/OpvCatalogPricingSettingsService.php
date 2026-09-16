<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\Maintenance\MaintenanceNoteRepository;
use App\Models\MaintenanceNote;
use App\Support\StorePreorders\OpvCatalogPricingSettings;

final class OpvCatalogPricingSettingsService
{
    public const string KEY = 'opv_catalog_pricing';

    /** @var array{price_multiplier: string, default_deposit_percent: string}|null */
    private ?array $cached = null;

    public function __construct(
        private readonly MaintenanceNoteRepository $notes,
    ) {}

    /**
     * @return array{price_multiplier: string, default_deposit_percent: string}
     */
    public function get(): array
    {
        return $this->cached ??= $this->load();
    }

    public function multiplier(): string
    {
        return $this->get()['price_multiplier'];
    }

    public function defaultDepositPercent(): string
    {
        return $this->get()['default_deposit_percent'];
    }

    public function isUsingDefaults(): bool
    {
        $note = $this->notes->findByKey(self::KEY);
        $body = $note?->body;

        return ! is_string($body) || trim($body) === '';
    }

    /**
     * @param  array{price_multiplier: string, default_deposit_percent: string}  $settings
     */
    public function upsert(array $settings): MaintenanceNote
    {
        $this->cached = null;

        return $this->notes->upsert(self::KEY, OpvCatalogPricingSettings::encode($settings));
    }

    public function resetToDefaults(): void
    {
        $this->cached = null;
        $note = $this->notes->findByKey(self::KEY);
        if ($note !== null) {
            $note->delete();
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $note = $this->notes->findByKey(self::KEY);
        $defaults = OpvCatalogPricingSettings::defaults();
        $settings = $this->get();

        return [
            'price_multiplier' => $settings['price_multiplier'],
            'default_deposit_percent' => $settings['default_deposit_percent'],
            'default_price_multiplier' => $defaults['price_multiplier'],
            'default_default_deposit_percent' => $defaults['default_deposit_percent'],
            'is_default' => $this->isUsingDefaults(),
            'updated_at' => $note?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{price_multiplier: string, default_deposit_percent: string}
     */
    private function load(): array
    {
        $note = $this->notes->findByKey(self::KEY);
        $body = $note?->body;
        if (! is_string($body) || trim($body) === '') {
            return OpvCatalogPricingSettings::defaults();
        }

        try {
            return OpvCatalogPricingSettings::decodeStoredBody($body) ?? OpvCatalogPricingSettings::defaults();
        } catch (\InvalidArgumentException) {
            return OpvCatalogPricingSettings::defaults();
        }
    }
}
