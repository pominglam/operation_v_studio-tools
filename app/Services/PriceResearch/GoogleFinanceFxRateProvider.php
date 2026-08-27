<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\Services\PriceResearch\Http\ExternalHtmlClient;

final class GoogleFinanceFxRateProvider
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

        $url = 'https://www.google.com/finance/quote/'
            .rawurlencode($fromCurrency)
            .'-'
            .rawurlencode($toCurrency);

        $res = $this->http->get($url, ['Accept' => 'text/html'], 'google_finance_fx');
        if (! $res->successful()) {
            throw new \RuntimeException('Google Finance FX lookup failed: HTTP '.$res->status());
        }

        $pattern = '/'.preg_quote("{$fromCurrency} / {$toCurrency}", '/')
            .'",\d+,null,\[[^\]]+\],null,([0-9.]+)/';

        if (! preg_match($pattern, $res->body(), $matches) || ! is_numeric($matches[1])) {
            throw new \RuntimeException('Google Finance FX lookup failed: missing rate.');
        }

        $rate = (float) $matches[1];
        if ($rate <= 0) {
            throw new \RuntimeException('Google Finance FX lookup failed: invalid rate.');
        }

        return $rate;
    }
}
