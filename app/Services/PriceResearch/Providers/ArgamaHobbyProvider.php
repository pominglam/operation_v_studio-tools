<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;

final class ArgamaHobbyProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'argama_hobby';
    }

    public function siteName(): string
    {
        return config('price_research.sites.argama_hobby.name', 'Argama Hobby');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.argama_hobby.base_url', 'https://argamahobby.com');
    }

    protected function maxCandidateProductUrlsToCheck(): int
    {
        // Argama search pages often return many close matches. Check a few more PDP candidates before giving up.
        return 6;
    }

    /**
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    protected function orderCandidateProductUrls(Product $product, array $links): array
    {
        $desc = mb_strtolower(trim((string) ($product->description ?? '')));
        $tokens = preg_split('/[^a-z0-9]+/i', $desc) ?: [];
        $tokens = array_values(array_unique(array_filter(array_map(static fn (string $t): string => mb_strtolower($t), $tokens))));

        $wantGod = in_array('god', $tokens, true);
        $wantGundam = in_array('gundam', $tokens, true);
        $wantRg = in_array('rg', $tokens, true);
        $want144 = in_array('144', $tokens, true);

        usort($links, function (string $a, string $b) use ($wantGod, $wantGundam, $wantRg, $want144): int {
            $aL = mb_strtolower($a);
            $bL = mb_strtolower($b);

            $score = static function (string $u) use ($wantGod, $wantGundam, $wantRg, $want144): int {
                $s = 0;
                if (str_contains($u, 'gift-card')) {
                    $s -= 50;
                }
                if (str_contains($u, 'decal') || str_contains($u, 'sticker')) {
                    $s -= 8;
                }

                if ($wantGod && str_contains($u, 'god')) {
                    $s += 12;
                }
                if ($wantGundam && str_contains($u, 'gundam')) {
                    $s += 8;
                }
                if ($wantRg && str_contains($u, 'rg')) {
                    $s += 3;
                }
                if ($want144 && str_contains($u, '144')) {
                    $s += 3;
                }

                // Prefer shorter URLs when tie-breaking.
                $s -= (int) floor(mb_strlen($u) / 80);

                return $s;
            };

            $aScore = $score($aL);
            $bScore = $score($bL);

            if ($aScore === $bScore) {
                return mb_strlen($a) <=> mb_strlen($b);
            }

            return $bScore <=> $aScore;
        });

        return $links;
    }
}
