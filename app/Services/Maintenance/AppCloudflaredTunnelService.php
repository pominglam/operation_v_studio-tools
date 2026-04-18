<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use Illuminate\Support\Facades\Storage;

final class AppCloudflaredTunnelService
{
    private const string DOCKER_SOCKET = '/var/run/docker.sock';

    private const string CONTAINER_NAME = 'pricing-tool-cloudflared-app';

    private const string IMAGE = 'cloudflare/cloudflared:2024.12.0';

    private const string URL_CACHE_PATH = 'maintenance/cloudflared_app_tunnel_urls.json';

    public function __construct(
        private readonly CloudflareQuickTunnelVerifier $verifier,
    ) {}

    /**
     * @return array{
     *   running:bool,
     *   tunnel_url:string|null,
     *   container_id:string|null,
     *   error:string|null,
     *   reachable:bool|null,
     *   reachable_http_status:int|null,
     *   reachable_checked_at:string|null,
     *   reachable_error:string|null
     * }
     */
    public function status(): array
    {
        if (! file_exists(self::DOCKER_SOCKET)) {
            return [
                'running' => false,
                'tunnel_url' => null,
                'container_id' => null,
                'error' => 'Docker socket not available.',
                'reachable' => null,
                'reachable_http_status' => null,
                'reachable_checked_at' => null,
                'reachable_error' => null,
            ];
        }

        $container = $this->getContainerByName(self::CONTAINER_NAME);
        if ($container === null) {
            return [
                'running' => false,
                'tunnel_url' => null,
                'container_id' => null,
                'error' => null,
                'reachable' => null,
                'reachable_http_status' => null,
                'reachable_checked_at' => null,
                'reachable_error' => null,
            ];
        }

        $running = (bool) ($container['State']['Running'] ?? false);
        $id = (string) ($container['Id'] ?? '');
        $startedAtUnix = $this->containerStartedAtUnix($container);

        $url = null;
        if ($running && $id !== '') {
            // Prefer cached URL, but if the container restarted the hostname can rotate.
            // Always attempt to read the latest URL from logs and override the cache if needed.
            $url = $this->cachedTunnelUrl($id);
            $logUrl = $this->extractTryCloudflareUrlFromLogs($id, $startedAtUnix);
            if (is_string($logUrl) && trim($logUrl) !== '') {
                $url = trim($logUrl);
            }
            if (is_string($url) && trim($url) !== '') {
                $this->cacheTunnelUrl($id, $url);
            } else {
                $url = null;
            }
        }

        $reachable = null;
        $reachableHttpStatus = null;
        $reachableCheckedAt = null;
        $reachableError = null;
        // NOTE: We intentionally do not probe the tunnel URL here.
        // Probing can be slow/flaky from inside Docker depending on DNS/networking, and
        // this method is called frequently by the Maintenance UI.

        return [
            'running' => $running,
            'tunnel_url' => $url,
            'container_id' => $id !== '' ? $id : null,
            'error' => null,
            'reachable' => $reachable,
            'reachable_http_status' => $reachableHttpStatus,
            'reachable_checked_at' => $reachableCheckedAt,
            'reachable_error' => $reachableError,
        ];
    }

    /**
     * @return array{ok:bool, tunnel_url:string|null, error:string|null}
     */
    public function start(): array
    {
        if (! file_exists(self::DOCKER_SOCKET)) {
            return ['ok' => false, 'tunnel_url' => null, 'error' => 'Docker socket not available.'];
        }

        $container = $this->getContainerByName(self::CONTAINER_NAME);
        if ($container === null) {
            $created = $this->createContainer();
            if (! $created['ok']) {
                return ['ok' => false, 'tunnel_url' => null, 'error' => $created['error'] ?? 'Create container failed.'];
            }
            $container = $this->getContainerByName(self::CONTAINER_NAME);
        }

        if ($container === null) {
            return ['ok' => false, 'tunnel_url' => null, 'error' => 'cloudflared app container not found after create.'];
        }

        $id = (string) ($container['Id'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'tunnel_url' => null, 'error' => 'cloudflared app container id missing.'];
        }

        $running = (bool) ($container['State']['Running'] ?? false);
        $startedAtUnix = $this->containerStartedAtUnix($container);

        if ($running) {
            $url = $this->cachedTunnelUrl($id);
            if (! is_string($url) || trim($url) === '') {
                // URL can take a few seconds to show up in logs after container start.
                for ($i = 0; $i < 10; $i++) {
                    usleep(300_000);
                    $url = $this->extractTryCloudflareUrlFromLogs($id, $startedAtUnix);
                    if (is_string($url) && trim($url) !== '') {
                        break;
                    }
                }
            }
            if (is_string($url) && trim($url) !== '') {
                $this->cacheTunnelUrl($id, $url);

                return ['ok' => true, 'tunnel_url' => $url, 'error' => null];
            }

            // Container is running; tunnel URL may simply not be in the logs yet.
            // Return quickly and let the UI use Refresh to pick up the URL.
            return ['ok' => true, 'tunnel_url' => null, 'error' => null];
        }

        $res = $this->dockerPost("/containers/{$id}/start");
        if (! $res['ok']) {
            return ['ok' => false, 'tunnel_url' => null, 'error' => 'Docker API error ('.$res['code'].')'.($res['body'] ? ': '.$res['body'] : '.')];
        }

        $url = null;
        // Creating a quick tunnel can take ~5-10s before the URL is printed.
        for ($i = 0; $i < 30; $i++) {
            usleep(300_000);
            $url = $this->extractTryCloudflareUrlFromLogs($id, $startedAtUnix);
            if (is_string($url) && trim($url) !== '') {
                break;
            }
        }
        if (is_string($url) && trim($url) !== '') {
            $this->cacheTunnelUrl($id, $url);
        }

        return [
            // Tunnel can be up even if the hostname hasn't propagated yet.
            'ok' => true,
            'tunnel_url' => $url,
            'error' => null,
        ];
    }

    /**
     * @return array{ok:bool, error:string|null}
     */
    public function stop(): array
    {
        if (! file_exists(self::DOCKER_SOCKET)) {
            return ['ok' => false, 'error' => 'Docker socket not available.'];
        }

        $container = $this->getContainerByName(self::CONTAINER_NAME);
        if ($container === null) {
            return ['ok' => true, 'error' => null];
        }

        $id = (string) ($container['Id'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'error' => 'cloudflared app container id missing.'];
        }

        $res = $this->dockerPost("/containers/{$id}/stop?t=5");
        if (! $res['ok']) {
            return ['ok' => false, 'error' => 'Docker API error ('.$res['code'].')'.($res['body'] ? ': '.$res['body'] : '.')];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @return array{ok:bool, error:string|null}
     */
    private function createContainer(): array
    {
        // Create a container attached to the compose network, proxying to the app's php service.
        $spec = [
            'Image' => self::IMAGE,
            'Cmd' => ['tunnel', '--url', 'http://php:8080', '--protocol', 'http2', '--no-autoupdate'],
            'HostConfig' => [
                'RestartPolicy' => ['Name' => 'unless-stopped'],
            ],
            'NetworkingConfig' => [
                'EndpointsConfig' => [
                    'pricing-tool-net' => new \stdClass,
                ],
            ],
        ];

        $body = json_encode($spec);
        if (! is_string($body)) {
            return ['ok' => false, 'error' => 'Failed to encode Docker container spec.'];
        }

        $res = $this->dockerPost('/containers/create?name='.rawurlencode(self::CONTAINER_NAME), $body);
        if (! $res['ok']) {
            return ['ok' => false, 'error' => 'Docker API error ('.$res['code'].')'.($res['body'] ? ': '.$res['body'] : '.')];
        }

        return ['ok' => true, 'error' => null];
    }

    private function getContainerByName(string $name): ?array
    {
        $json = $this->dockerGet('/containers/'.rawurlencode($name).'/json');
        if ($json === null) {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function containerStartedAtUnix(array $container): ?int
    {
        $startedAt = $container['State']['StartedAt'] ?? null;
        if (! is_string($startedAt) || trim($startedAt) === '') {
            return null;
        }
        $ts = strtotime($startedAt);

        return is_int($ts) ? $ts : null;
    }

    private function extractTryCloudflareUrlFromLogs(string $containerId, ?int $startedAtUnix): ?string
    {
        // Always prefer tail: since/until can be huge and slow for long-lived containers.
        // The trycloudflare hostname is printed on startup and should be present in the recent log tail.
        $raw = $this->dockerGet("/containers/{$containerId}/logs?stdout=1&stderr=1&tail=600");

        return $this->parseTryCloudflareUrl($raw);
    }

    private function parseTryCloudflareUrl(?string $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        if (preg_match_all('#https?://[a-z0-9-]+\\.trycloudflare\\.com#i', $raw, $m)) {
            $all = $m[0] ?? [];
            $last = $all !== [] ? $all[count($all) - 1] : null;
            if (is_string($last) && trim($last) !== '') {
                return trim($last);
            }
        }

        return null;
    }

    private function looksLikeDnsFailure(string $error): bool
    {
        $e = strtolower(trim($error));
        if ($e === '') {
            return false;
        }

        return str_contains($e, 'could not resolve host')
            || str_contains($e, 'name resolution')
            || str_contains($e, 'enotfound')
            || str_contains($e, 'dns');
    }

    /**
     * trycloudflare can return HTTP 530 (1033) while the quick tunnel hostname propagates.
     * Wait a short period for it to return something other than 530.
     */
    private function waitUntilTunnelReady(string $tunnelUrl, int $maxSeconds): bool
    {
        $tunnelUrl = rtrim(trim($tunnelUrl), '/');
        if ($tunnelUrl === '') {
            return false;
        }

        $deadline = microtime(true) + max(1, $maxSeconds);
        $probeUrl = "{$tunnelUrl}/";

        while (microtime(true) < $deadline) {
            try {
                $res = \Illuminate\Support\Facades\Http::timeout(4)->connectTimeout(2)->head($probeUrl);
                if ($res->status() !== 530) {
                    return true;
                }
            } catch (\Throwable) {
                // ignore and retry
            }
            usleep(900_000);
        }

        return false;
    }

    private function cachedTunnelUrl(string $containerId): ?string
    {
        $containerId = trim($containerId);
        if ($containerId === '') {
            return null;
        }

        $container = $this->getContainerByName(self::CONTAINER_NAME);
        $startedAtUnix = is_array($container) ? $this->containerStartedAtUnix($container) : null;

        $disk = Storage::disk('local');
        if (! $disk->exists(self::URL_CACHE_PATH)) {
            return null;
        }

        $raw = (string) $disk->get(self::URL_CACHE_PATH);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        // Invalidate cache if the container restarted since we cached the URL.
        $cachedStarted = $decoded[$containerId]['started_at_unix'] ?? null;
        if (is_int($startedAtUnix) && is_int($cachedStarted) && $cachedStarted !== $startedAtUnix) {
            return null;
        }

        $url = $decoded[$containerId]['tunnel_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    private function cacheTunnelUrl(string $containerId, string $tunnelUrl): void
    {
        $containerId = trim($containerId);
        $tunnelUrl = trim($tunnelUrl);
        if ($containerId === '' || $tunnelUrl === '') {
            return;
        }

        $container = $this->getContainerByName(self::CONTAINER_NAME);
        $startedAtUnix = is_array($container) ? $this->containerStartedAtUnix($container) : null;

        $disk = Storage::disk('local');
        $disk->makeDirectory('maintenance');

        $data = [];
        if ($disk->exists(self::URL_CACHE_PATH)) {
            $raw = (string) $disk->get(self::URL_CACHE_PATH);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $data[$containerId] = [
            'tunnel_url' => $tunnelUrl,
            'started_at_unix' => $startedAtUnix,
            'cached_at' => now()->toISOString(),
        ];

        $disk->put(self::URL_CACHE_PATH, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function dockerGet(string $path): ?string
    {
        $path = '/'.ltrim($path, '/');
        $ch = curl_init('http://localhost'.$path);
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, self::DOCKER_SOCKET);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($out === false) {
            return null;
        }
        if ($code === 404) {
            return null;
        }
        if ($code < 200 || $code >= 300) {
            return null;
        }

        return is_string($out) ? $out : null;
    }

    /**
     * @return array{ok:bool, code:int|null, body:string|null}
     */
    private function dockerPost(string $path, ?string $body = null): array
    {
        $path = '/'.ltrim($path, '/');
        $ch = curl_init('http://localhost'.$path);
        if ($ch === false) {
            return ['ok' => false, 'code' => null, 'body' => null];
        }

        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, self::DOCKER_SOCKET);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if (is_string($body)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $ok = $code >= 200 && $code < 300;

        return ['ok' => $ok, 'code' => $code, 'body' => is_string($out) ? $out : null];
    }
}
