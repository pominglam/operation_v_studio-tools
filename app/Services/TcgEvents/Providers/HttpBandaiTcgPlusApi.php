<?php

declare(strict_types=1);

namespace App\Services\TcgEvents\Providers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class HttpBandaiTcgPlusApi implements BandaiTcgPlusApi
{
    private const string ApiBaseUrl = 'https://api.bandai-tcg-plus.com';

    public function __construct() {}

    public function listEvents(array $params): array
    {
        $url = self::ApiBaseUrl.'/api/user/event/list';

        $response = $this->getJson($url, $params);
        $success = $this->requireSuccess($response);

        $events = $success['event_list'] ?? null;
        $total = $success['total'] ?? null;
        if (! is_array($events) || ! is_int($total)) {
            throw new RuntimeException('Unexpected response shape from Bandai list endpoint.');
        }

        /** @var array<int, array<string, mixed>> $events */
        return [
            'events' => $events,
            'total' => $total,
        ];
    }

    public function getEventDetail(int $eventId): array
    {
        $url = self::ApiBaseUrl.'/api/user/event/'.(string) $eventId;

        $response = $this->getJson($url);
        $success = $this->requireSuccess($response);

        $event = $success['event'] ?? null;
        $countApplicants = $success['count_applicants'] ?? null;
        if (! is_array($event) || (! is_int($countApplicants) && $countApplicants !== null)) {
            throw new RuntimeException('Unexpected response shape from Bandai detail endpoint.');
        }

        /** @var array<string, mixed> $event */
        return [
            'event' => $event,
            'count_applicants' => $countApplicants,
        ];
    }

    public function getEventDetails(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_values(array_filter($eventIds, static fn (mixed $v): bool => is_int($v) && $v > 0))));
        if ($eventIds === []) {
            return [];
        }

        $traceId = (string) Str::uuid();

        $results = [];
        foreach (array_chunk($eventIds, 10) as $chunk) {
            $startedAt = microtime(true);

            Log::channel('external_api')->info('external_request', [
                'trace_id' => $traceId,
                'method' => 'GET',
                'url' => self::ApiBaseUrl.'/api/user/event/{id}',
                'site_key' => 'bandai_tcg_plus',
                'created_at' => now()->toISOString(),
                'batch_count' => count($chunk),
            ]);

            /** @var array<int, Response> $responses */
            $responses = Http::pool(function (Pool $pool) use ($chunk): array {
                $reqs = [];
                foreach ($chunk as $id) {
                    $reqs[$id] = $pool
                        ->as((string) $id)
                        ->connectTimeout(3)
                        ->timeout(20)
                        ->retry(
                            3,
                            static fn (int $attempt): int => (int) min(8000, 250 * (2 ** max(0, $attempt - 1))),
                            static fn ($exception, Response $response): bool => $response->status() >= 500 || in_array($response->status(), [429, 503], true),
                            throw: false,
                        )
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                            'Accept' => 'application/json',
                            'Accept-Language' => 'en-CA,en;q=0.9',
                        ])
                        ->get(self::ApiBaseUrl.'/api/user/event/'.(string) $id);
                }

                return $reqs;
            });

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            Log::channel('external_api')->info('external_response', [
                'trace_id' => $traceId,
                'method' => 'GET',
                'url' => self::ApiBaseUrl.'/api/user/event/{id}',
                'site_key' => 'bandai_tcg_plus',
                'status' => 200,
                'duration_ms' => $durationMs,
                'updated_at' => now()->toISOString(),
                'batch_count' => count($chunk),
            ]);

            foreach ($responses as $eventId => $resp) {
                if (! $resp->successful()) {
                    continue;
                }
                $json = $resp->json();
                if (! is_array($json)) {
                    continue;
                }
                $success = $json['success'] ?? null;
                if (! is_array($success)) {
                    continue;
                }
                $event = $success['event'] ?? null;
                $countApplicants = $success['count_applicants'] ?? null;
                if (! is_array($event) || (! is_int($countApplicants) && $countApplicants !== null)) {
                    continue;
                }

                /** @var array<string, mixed> $event */
                $results[(int) $eventId] = [
                    'event' => $event,
                    'count_applicants' => $countApplicants,
                ];
            }
        }

        return $results;
    }

    public function getGameFormatMap(): array
    {
        $url = self::ApiBaseUrl.'/api/masterdata';

        $response = $this->getJson($url);
        $success = $this->requireSuccess($response);

        $gameFormat = $success['game_format'] ?? null;
        if (! is_array($gameFormat)) {
            throw new RuntimeException('Unexpected response shape from Bandai masterdata endpoint.');
        }

        $map = [];
        foreach ($gameFormat as $id => $row) {
            if (! is_array($row) || ! isset($row['format_name'])) {
                continue;
            }
            $formatName = $row['format_name'];
            if (! is_string($formatName) || trim($formatName) === '') {
                continue;
            }

            $idInt = is_numeric($id) ? (int) $id : null;
            if ($idInt === null || $idInt <= 0) {
                continue;
            }

            $map[$idInt] = $formatName;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function getJson(string $url, array $params = []): Response
    {
        $qs = $params === [] ? '' : '?'.http_build_query($params);

        $traceId = (string) Str::uuid();
        $startedAt = microtime(true);

        Log::channel('external_api')->info('external_request', [
            'trace_id' => $traceId,
            'method' => 'GET',
            'url' => $url.$qs,
            'site_key' => 'bandai_tcg_plus',
            'created_at' => now()->toISOString(),
        ]);

        $response = Http::connectTimeout(3)
            ->timeout(20)
            ->retry(
                5,
                static fn (int $attempt): int => (int) min(8000, 250 * (2 ** max(0, $attempt - 1))),
                static fn ($exception, Response $response): bool => $response->status() >= 500 || in_array($response->status(), [429, 503], true),
                throw: false,
            )
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
                'Accept-Language' => 'en-CA,en;q=0.9',
            ])
            ->get($url.$qs);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        Log::channel('external_api')->info('external_response', [
            'trace_id' => $traceId,
            'method' => 'GET',
            'url' => $url.$qs,
            'site_key' => 'bandai_tcg_plus',
            'status' => $response->status(),
            'duration_ms' => $durationMs,
            'updated_at' => now()->toISOString(),
        ]);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireSuccess(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException('Bandai API request failed with status '.$response->status());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Bandai API returned invalid JSON.');
        }

        $success = $json['success'] ?? null;
        if (! is_array($success)) {
            throw new RuntimeException('Bandai API response missing success payload.');
        }

        return $success;
    }
}

