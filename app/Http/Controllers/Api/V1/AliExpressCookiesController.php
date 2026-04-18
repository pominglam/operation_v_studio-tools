<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AliExpressCookiesUpsertRequest;
use App\Services\PriceResearch\Http\AliExpressScraperClient;
use Illuminate\Http\JsonResponse;

final class AliExpressCookiesController extends Controller
{
    public function __construct(
        private readonly AliExpressScraperClient $scraper,
    ) {}

    public function store(AliExpressCookiesUpsertRequest $request): JsonResponse
    {
        /** @var array<int, array<string, mixed>> $cookies */
        $cookies = (array) $request->validated('cookies');

        $ok = $this->scraper->setCookies($cookies);
        if (! $ok) {
            return response()->json([
                'message' => 'Failed to upload cookies to scraper.',
            ], 502);
        }

        return response()->json([
            'message' => 'AliExpress cookies uploaded.',
            'count' => count($cookies),
        ]);
    }
}
