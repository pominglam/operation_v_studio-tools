<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\Services\PriceResearch\Http\ExternalHtmlClient;
use Illuminate\Support\Facades\Cache;

final class FxRateService
{
    public function __construct(
        private readonly ExternalHtmlClient $http,
    ) {}

    /**
     * Returns a multiplicative rate so that: amount_in_to = amount_in_from * rate(from->to).
     */
    public function rate(string $fromCurrency, string $toCurrency): float
    {
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));

        if ($fromCurrency === '' || $toCurrency === '') {
            throw new \InvalidArgumentException('Currency code is required.');
        }

        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        $cacheKey = 'fx:rate:'.$fromCurrency.':'.$toCurrency.':'.now()->format('Y-m-d');

        /** @var float $rate */
        $rate = Cache::remember($cacheKey, now()->addHours(24), function () use ($fromCurrency, $toCurrency): float {
            // Frankfurter is a free FX endpoint backed by ECB rates. CAD is supported.
            $url = 'https://api.frankfurter.app/latest?from='.rawurlencode($fromCurrency).'&to='.rawurlencode($toCurrency);
            $res = $this->http->get($url, ['Accept' => 'application/json'], 'fx_rates');
            if (! $res->successful()) {
                throw new \RuntimeException('FX rate lookup failed: HTTP '.$res->status());
            }

            /** @var array<string, mixed> $json */
            $json = $res->json() ?? [];
            /** @var array<string, mixed> $rates */
            $rates = is_array($json['rates'] ?? null) ? $json['rates'] : [];
            $raw = $rates[$toCurrency] ?? null;
            if (! is_numeric($raw)) {
                throw new \RuntimeException('FX rate lookup failed: missing rate.');
            }

            $rate = (float) $raw;
            if ($rate <= 0) {
                throw new \RuntimeException('FX rate lookup failed: invalid rate.');
            }

            return $rate;
        });

        return $rate;
    }
}



