<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Support;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use Illuminate\Support\Facades\Log;

final class ShopifyAsyncJobWaitService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
    ) {}

    public function waitUntilDone(
        string $jobGid,
        int $maxWaitSeconds = 120,
        int $pollIntervalMs = 1000,
    ): bool {
        if ($jobGid === '') {
            return false;
        }

        $deadline = microtime(true) + max(1, $maxWaitSeconds);
        while (microtime(true) < $deadline) {
            $response = $this->client->query(ShopifyAdminGraphQlQueries::JOB_STATUS, [
                'id' => $jobGid,
            ]);
            $done = $response['data']['job']['done'] ?? null;
            if ($done === true) {
                Log::channel('shopify')->info('shopify.async_job.done', [
                    'job_id' => $jobGid,
                ]);

                return true;
            }

            usleep(max(100, $pollIntervalMs) * 1000);
        }

        Log::channel('shopify')->warning('shopify.async_job.wait_timeout', [
            'job_id' => $jobGid,
            'max_wait_seconds' => $maxWaitSeconds,
        ]);

        return false;
    }
}
