<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class PriceResearchFilterOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var array<int, string> $disabled */
        $disabled = (array) config('price_research.disabled_site_keys', []);
        $disabled = array_values(array_unique(array_filter(array_map(
            static fn (string $v): string => trim($v),
            $disabled,
        ), static fn (string $v): bool => $v !== '')));
        $disabledMap = array_fill_keys($disabled, true);

        /** @var array<string, array{name?: string}> $sites */
        $sites = (array) config('price_research.sites', []);
        $siteList = [];
        foreach ($sites as $key => $site) {
            if (! is_string($key)) {
                continue;
            }
            if (isset($disabledMap[$key])) {
                continue;
            }
            $name = is_array($site) ? ($site['name'] ?? null) : null;
            $siteList[] = [
                'key' => $key,
                'name' => is_string($name) && trim($name) !== '' ? $name : $key,
            ];
        }

        return response()->json([
            'data' => [
                'disabled_site_keys' => $disabled,
                'sites' => $siteList,
            ],
        ]);
    }
}
