<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\DTOs\Plamod\PlamodPreorderInterestResult;
use App\Models\PlamodPreorder;

final class PlamodPreorderInterestService
{
    /**
     * @param  list<string>  $skus
     */
    public function setNotInterested(array $skus, bool $notInterested): PlamodPreorderInterestResult
    {
        $skus = $this->normalizeSkus($skus);
        if ($skus === []) {
            return new PlamodPreorderInterestResult(0, 0, $notInterested);
        }

        $updated = PlamodPreorder::query()
            ->whereIn('sku', $skus)
            ->update([
                'not_interested_at' => $notInterested ? now() : null,
            ]);

        return new PlamodPreorderInterestResult(count($skus), $updated, $notInterested);
    }

    /**
     * @param  list<string>  $skus
     */
    public function clear(array $skus): PlamodPreorderInterestResult
    {
        return $this->setNotInterested($skus, false);
    }

    /**
     * @param  list<string>  $skus
     * @return list<string>
     */
    private function normalizeSkus(array $skus): array
    {
        $out = [];
        foreach ($skus as $sku) {
            $trimmed = trim($sku);
            if ($trimmed === '') {
                continue;
            }
            $out[$trimmed] = $trimmed;
        }

        return array_values($out);
    }
}
