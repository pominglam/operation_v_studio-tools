<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\PriceResearchRun;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class EloquentPriceResearchRunRepository implements PriceResearchRunRepository
{
    public function create(bool $force, int $ttlDays, int $totalSites, int $totalProducts): PriceResearchRun
    {
        /** @var PriceResearchRun $run */
        $run = PriceResearchRun::query()->create([
            'status' => 'queued',
            'force' => $force,
            'ttl_days' => $ttlDays,
            'total_sites' => $totalSites,
            'total_products' => $totalProducts,
        ]);

        return $run;
    }

    public function findByUuidOrFail(string $uuid): PriceResearchRun
    {
        /** @var PriceResearchRun|null $run */
        $run = PriceResearchRun::query()->where('uuid', $uuid)->first();
        if ($run === null) {
            throw (new ModelNotFoundException())->setModel(PriceResearchRun::class, [$uuid]);
        }

        return $run;
    }

    public function latest(): ?PriceResearchRun
    {
        /** @var PriceResearchRun|null $run */
        $run = PriceResearchRun::query()->orderByDesc('id')->first();
        return $run;
    }

    public function save(PriceResearchRun $run): PriceResearchRun
    {
        $run->save();
        return $run;
    }
}


