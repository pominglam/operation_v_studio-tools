<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\DAL\RuntimeSettings\RuntimeSettingRepository;

final class PlamodRestockSettingsService
{
    public const string RUNTIME_KEY = 'plamod_restock.shipping_percent';

    private const string EXCLUDED_SERIES_KEY = 'plamod_restock.excluded_series';

    private const string EXCLUDED_PRODUCT_TERMS_KEY = 'plamod_restock.excluded_product_terms';

    private const float DEFAULT_SHIPPING_PERCENT = 5.0;

    public function __construct(
        private readonly RuntimeSettingRepository $settings,
    ) {}

    /**
     * @return array{shipping_percent: float, excluded_series: array<int, string>, excluded_product_terms: array<int, string>}
     */
    public function get(): array
    {
        $raw = $this->settings->getString(self::RUNTIME_KEY);

        return [
            'shipping_percent' => is_numeric($raw)
                ? max(0.0, min(100.0, (float) $raw))
                : self::DEFAULT_SHIPPING_PERCENT,
            'excluded_series' => $this->readList(self::EXCLUDED_SERIES_KEY),
            'excluded_product_terms' => $this->readList(self::EXCLUDED_PRODUCT_TERMS_KEY),
        ];
    }

    /**
     * @param  array<int, string>|null  $excludedSeries
     * @param  array<int, string>|null  $excludedProductTerms
     * @return array{shipping_percent: float, excluded_series: array<int, string>, excluded_product_terms: array<int, string>}
     */
    public function save(
        float $shippingPercent,
        ?array $excludedSeries = null,
        ?array $excludedProductTerms = null,
    ): array {
        $normalized = max(0.0, min(100.0, $shippingPercent));
        $this->settings->putString(self::RUNTIME_KEY, number_format($normalized, 2, '.', ''));
        if ($excludedSeries !== null) {
            $this->writeList(self::EXCLUDED_SERIES_KEY, $excludedSeries);
        }
        if ($excludedProductTerms !== null) {
            $this->writeList(self::EXCLUDED_PRODUCT_TERMS_KEY, $excludedProductTerms);
        }

        return $this->get();
    }

    /**
     * @param  array{shipping_percent: float, excluded_series: array<int, string>, excluded_product_terms: array<int, string>}  $settings
     */
    public function matchesExclusion(
        ?string $productName,
        ?string $series,
        array $settings,
    ): bool {
        $normalizedSeries = mb_strtolower(trim((string) $series));
        foreach ($settings['excluded_series'] as $excludedSeries) {
            if ($normalizedSeries !== '' && $normalizedSeries === mb_strtolower($excludedSeries)) {
                return true;
            }
        }

        $normalizedName = mb_strtolower(trim((string) $productName));
        foreach ($settings['excluded_product_terms'] as $term) {
            if ($normalizedName !== '' && str_contains($normalizedName, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function readList(string $key): array
    {
        $decoded = json_decode($this->settings->getString($key) ?? '[]', true);

        return is_array($decoded) ? $this->normalizeList($decoded) : [];
    }

    /**
     * @param  array<int, string>  $values
     */
    private function writeList(string $key, array $values): void
    {
        $this->settings->putString($key, json_encode($this->normalizeList($values), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $trimmed = is_string($value) ? trim($value) : '';
            if ($trimmed !== '') {
                $normalized[mb_strtolower($trimmed)] = $trimmed;
            }
        }

        return array_values($normalized);
    }
}
