<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\DTOs\StorePreorders\StorePreorderExpireResult;
use App\Models\StorePreorder;
use App\Services\StorePreorders\Exceptions\StorePreorderCloseException;
use Illuminate\Support\Carbon;

final class StorePreorderExpireService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly StorePreorderCloseService $close,
        private readonly StorePreorderShopifyShelfPushService $shopify,
    ) {}

    public function closeEndedWindows(?string $onYmd = null): StorePreorderExpireResult
    {
        $day = $this->torontoDay($onYmd);
        $offers = $this->offers->listOpenEndedBefore($day);
        if ($offers->isEmpty()) {
            return new StorePreorderExpireResult(0, 0, 0, 0, []);
        }

        $uuids = $offers
            ->map(static fn (StorePreorder $offer): string => (string) $offer->uuid)
            ->filter(static fn (string $uuid): bool => $uuid !== '')
            ->values()
            ->all();
        $push = $this->pushWhileStillOpen($uuids);
        $closed = 0;
        $errors = $push['errors'];

        foreach ($offers as $offer) {
            if (! $offer instanceof StorePreorder) {
                continue;
            }
            try {
                $this->close->close((string) $offer->uuid);
                $closed++;
            } catch (StorePreorderCloseException $exception) {
                $errors[] = [
                    'sku' => trim((string) ($offer->product?->sku ?? $offer->plamod_sku)),
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return new StorePreorderExpireResult(
            $offers->count(),
            $closed,
            $push['pushed'],
            $push['failed'],
            $errors,
        );
    }

    /**
     * @param  list<string>  $uuids
     * @return array{pushed: int, failed: int, errors: list<array{sku: string, message: string}>}
     */
    private function pushWhileStillOpen(array $uuids): array
    {
        try {
            $result = $this->shopify->pushOpen($uuids);
        } catch (\Throwable $exception) {
            return [
                'pushed' => 0,
                'failed' => count($uuids),
                'errors' => [['sku' => '', 'message' => $exception->getMessage()]],
            ];
        }

        return [
            'pushed' => $result->pushed,
            'failed' => $result->failed,
            'errors' => $result->errors,
        ];
    }

    private function torontoDay(?string $onYmd): string
    {
        $trimmed = substr(trim((string) $onYmd), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return $trimmed;
        }

        return Carbon::now('America/Toronto')->toDateString();
    }
}
