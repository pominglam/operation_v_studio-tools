<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use Illuminate\Support\Str;

final class ShopifyImageUrlSigner
{
    private const int CLOCK_SKEW_SECONDS = 60;

    /**
     * @return array{expires:int, signature:string}
     */
    public function sign(int $assetId, int $expiresAtUnix): array
    {
        $expiresAtUnix = max(0, $expiresAtUnix);
        $sig = $this->signatureFor($assetId, $expiresAtUnix);

        return [
            'expires' => $expiresAtUnix,
            'signature' => $sig,
        ];
    }

    public function isValid(int $assetId, int $expiresAtUnix, string $signature): bool
    {
        if ($expiresAtUnix <= 0) {
            return false;
        }

        $now = time();
        if ($expiresAtUnix < ($now - self::CLOCK_SKEW_SECONDS)) {
            return false;
        }

        $expected = $this->signatureFor($assetId, $expiresAtUnix);

        return hash_equals($expected, $signature);
    }

    private function signatureFor(int $assetId, int $expiresAtUnix): string
    {
        $secret = (string) config('app.key', '');
        if (Str::startsWith($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                $secret = $decoded;
            }
        }

        return hash_hmac('sha256', $assetId.'|'.$expiresAtUnix, $secret);
    }
}
