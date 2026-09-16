<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorderOffer;
use Illuminate\Support\Facades\Storage;

final class PlamodPreorderOfferSidecarImportService
{
    public function __construct(
        private readonly PlamodPreorderOfferUpsertService $upserts,
    ) {}

    /**
     * @return array{skus: int}
     */
    public function importFromStoragePath(?string $sidecarPath): array
    {
        $path = is_string($sidecarPath) ? trim($sidecarPath) : '';
        if ($path === '') {
            return ['skus' => 0];
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return ['skus' => 0];
        }

        $decoded = json_decode((string) $disk->get($path), true);
        if (! is_array($decoded)) {
            return ['skus' => 0];
        }

        $imported = 0;
        foreach ($decoded as $sku => $offers) {
            if (! is_array($offers)) {
                continue;
            }

            $this->upserts->replaceForSku((string) $sku, $offers);
            if (PlamodPreorderOffer::query()->where('sku', '=', (string) $sku)->exists()) {
                $imported++;
            }
        }

        return ['skus' => $imported];
    }
}
