<?php

declare(strict_types=1);

namespace App\Support\PriceResearch;

final class CoolDragonSearchTerms
{
    public static function fromTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $title = preg_replace('/\s+\d+(?:\.\d+)?x\d+(?:\.\d+)?x\d+(?:\.\d+)?cm\b.*$/iu', '', $title) ?? $title;
        $title = preg_replace('/\s+\(\s*1\s*\/\s*\d+\s*\)\s*$/iu', '', $title) ?? $title;

        return trim($title);
    }

    public static function titlesLikelyMatch(string $candidateTitle, string $erpTitle): bool
    {
        $erp = mb_strtolower(self::fromTitle($erpTitle));
        $candidate = mb_strtolower($candidateTitle);
        if ($erp === '' || $candidate === '') {
            return false;
        }
        if (self::editionConflicts($erp, $candidate)) {
            return false;
        }

        $erpTokens = self::tokens($erp);
        $candidateTokens = self::tokens($candidate);
        if ($erpTokens === [] || $candidateTokens === []) {
            return false;
        }

        $hits = count(array_intersect($erpTokens, $candidateTokens));
        $longHits = 0;
        foreach ($erpTokens as $token) {
            if (mb_strlen($token) >= 6 && in_array($token, $candidateTokens, true)) {
                $longHits++;
            }
        }

        if ($longHits >= 1 && $hits >= 2) {
            return true;
        }
        if ($longHits >= 1 && mb_strlen(self::longestShared($erpTokens, $candidateTokens)) >= 7) {
            return true;
        }

        return $hits >= 2 && count($erpTokens) <= 3;
    }

    /**
     * @return array<int, string>
     */
    private static function tokens(string $text): array
    {
        $stop = [
            'snaa', 'knights', 'round', 'table', 'scale', 'edition', 'special',
            'color', 'colors', 'original', 'model', 'kit', 'the', 'and',
            'infinity', 'nova', 'chinese', 'version', 'ver',
        ];
        $parts = preg_split('/[^a-z0-9]+/i', $text) ?: [];
        $out = [];
        foreach ($parts as $token) {
            $token = trim(mb_strtolower((string) $token));
            if ($token === '' || in_array($token, $stop, true) || preg_match('/^\d+$/', $token) === 1) {
                continue;
            }
            if ($token !== 'dx' && mb_strlen($token) < 4) {
                continue;
            }
            $out[] = $token;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     */
    private static function longestShared(array $left, array $right): string
    {
        $best = '';
        foreach ($left as $token) {
            if (in_array($token, $right, true) && mb_strlen($token) > mb_strlen($best)) {
                $best = $token;
            }
        }

        return $best;
    }

    private static function editionConflicts(string $erp, string $candidate): bool
    {
        $erpDx = preg_match('/\b(dx|deluxe|awakened)\b/i', $erp) === 1;
        $erpOrdinary = preg_match('/\b(ordinary|standard|regular)\b/i', $erp) === 1;
        $candidateDx = preg_match('/\b(dx|deluxe|awakened)\b/i', $candidate) === 1;
        $candidateOrdinary = preg_match('/\b(ordinary|standard|regular)\b/i', $candidate) === 1;

        return ($erpDx && $candidateOrdinary && ! $candidateDx)
            || ($erpOrdinary && $candidateDx && ! $candidateOrdinary);
    }
}
