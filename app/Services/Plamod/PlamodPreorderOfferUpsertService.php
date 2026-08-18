<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorderOffer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PlamodPreorderOfferUpsertService
{
    /**
     * @param  array<int, array<string, mixed>>  $offers
     */
    public function replaceForSku(string $sku, array $offers): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            return;
        }

        $now = now();
        $normalized = [];
        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            $quantity = $this->parseQuantity($offer['quantity'] ?? null);
            if ($quantity <= 0) {
                continue;
            }

            $offerId = $this->nullableString($offer['offer_id'] ?? null);
            $etaDate = $this->parseDate($this->stringValue($offer['eta_date'] ?? null));
            $offerKey = $offerId !== null
                ? $offerId
                : sha1($sku.'|'.($etaDate ?? '').'|'.$quantity);

            $normalized[$offerKey] = [
                'sku' => $sku,
                'offer_key' => $offerKey,
                'offer_id' => $offerId,
                'quantity' => $quantity,
                'eta_date' => $etaDate,
                'po_due_date' => $this->parseDate($this->stringValue($offer['po_due_date'] ?? null)),
                'price_preorder' => $this->parseMoney($this->stringValue($offer['price_preorder'] ?? null)),
                'last_seen_at' => $now,
            ];
        }

        DB::transaction(function () use ($sku, $normalized, $now): void {
            if ($normalized === []) {
                PlamodPreorderOffer::query()->where('sku', '=', $sku)->delete();

                return;
            }

            $seenKeys = array_keys($normalized);
            foreach ($normalized as $row) {
                PlamodPreorderOffer::query()->updateOrCreate(
                    ['sku' => $sku, 'offer_key' => $row['offer_key']],
                    $row,
                );
            }

            PlamodPreorderOffer::query()
                ->where('sku', '=', $sku)
                ->whereNotIn('offer_key', $seenKeys)
                ->delete();
        });
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private function parseQuantity(mixed $value): int
    {
        $text = $this->stringValue($value);
        if ($text === '' || ! ctype_digit($text)) {
            return 0;
        }

        return max(0, (int) $text);
    }

    private function parseMoney(string $value): ?string
    {
        $value = trim(str_replace(['$', ','], '', $value));
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^([A-Za-z]{3,9})\s+(\d{1,2})$/', $value, $matches) === 1) {
            return $this->parseMonthDayDate($matches[1], (int) $matches[2]);
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseMonthDayDate(string $month, int $day): ?string
    {
        $year = (int) now()->format('Y');

        try {
            $parsed = Carbon::parse("{$month} {$day} {$year}");
            if ($parsed->isPast()) {
                $parsed = $parsed->addYear();
            }

            return $parsed->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
