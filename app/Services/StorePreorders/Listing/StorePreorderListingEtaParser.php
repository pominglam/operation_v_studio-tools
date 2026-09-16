<?php

declare(strict_types=1);

namespace App\Services\StorePreorders\Listing;

use Illuminate\Support\Carbon;

final class StorePreorderListingEtaParser
{
    public function fromText(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/\b(?:eta|arriving|arrival|release)\b[:\s-]*([a-z]{3,9}\.?\s+\d{4}|\d{4}-\d{2}(?:-\d{2})?)/i', $text, $match) === 1) {
            return $this->parseToken(trim($match[1]));
        }

        if (preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+(\d{4})\b/i', $text, $match) === 1) {
            return $this->parseToken($match[1].' '.$match[2]);
        }

        return null;
    }

    private function parseToken(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token) === 1) {
            return $token;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $token, $match) === 1) {
            return $match[1].'-'.$match[2].'-01';
        }

        try {
            $day = Carbon::parse($token)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }

        if ($day->year < 2020 || $day->year > 2100) {
            return null;
        }

        return $day->toDateString();
    }
}
