<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Storage;

final class CloudflaredTunnelService implements CloudflaredTunnel
{
    private const string DOCKER_SOCKET = '/var/run/docker.sock';

    private const string CONTAINER_NAME = 'pricing-tool-cloudflared';

    private const string URL_CACHE_PATH = 'shopify/cloudflared_tunnel_urls.json';

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
                'error' => 'cloudflared container not found. Run `docker compose up -d cloudflared shopify_images` once.',
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
            // Prefer cached URL (cloudflared prints the trycloudflare hostname once on startup,
            // and it can scroll out of the recent log tail quickly).
            $url = $this->cachedTunnelUrl($id);
            if ($url === null) {
                $url = $this->extractTryCloudflareUrlFromLogs($id, $startedAtUnix);
                if (is_string($url) && trim($url) !== '') {
                    $this->cacheTunnelUrl($id, $url);
                }
            }
        }

        $reachable = null;
        $reachableHttpStatus = null;
        $reachableCheckedAt = null;
        $reachableError = null;
        if ($running && is_string($url) && trim($url) !== '') {
            $check = $this->verifier->verify($url);
            $reachable = is_bool($check['reachable'] ?? null) ? $check['reachable'] : null;
            $reachableHttpStatus = is_int($check['http_status'] ?? null) ? $check['http_status'] : null;
            $reachableCheckedAt = is_string($check['checked_at'] ?? null) ? $check['checked_at'] : null;
            $reachableError = is_string($check['error'] ?? null) ? $check['error'] : null;
        } elseif ($running && $id !== '' && $url === null) {
            // Avoid misleading the UI: we did not perform a reachability check because
            // we couldn't discover the tunnel URL.
            $reachable = null;
            $reachableHttpStatus = null;
            $reachableCheckedAt = now()->toISOString();
            $reachableError = 'Tunnel URL not discovered yet. Click “Refresh tunnel” in a few seconds.';
        }

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
            return [
                'ok' => false,
                'tunnel_url' => null,
                'error' => 'Docker socket not available.',
            ];
        }

        $container = $this->getContainerByName(self::CONTAINER_NAME);
        if ($container === null) {
            return [
                'ok' => false,
                'tunnel_url' => null,
                'error' => 'cloudflared container not found. Run `docker compose up -d cloudflared shopify_images` once.',
            ];
        }

        $id = (string) ($container['Id'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'tunnel_url' => null, 'error' => 'cloudflared container id missing.'];
        }

        $running = (bool) ($container['State']['Running'] ?? false);
        $startedAtUnix = $this->containerStartedAtUnix($container);

        // IMPORTANT: Do not restart a running quick tunnel.
        // Restarting cloudflared can rotate the trycloudflare.com hostname, which would invalidate
        // previously-exported Shopify Image Src URLs while Shopify is still fetching them.
        if ($running) {
            $url = null;
            for ($i = 0; $i < 10; $i++) {
                $url = $this->extractTryCloudflareUrlFromLogs($id, $startedAtUnix);
                if (is_string($url) && trim($url) !== '') {
                    break;
                }
                usleep(250_000);
            }
            if (is_string($url) && trim($url) !== '') {
                $this->cacheTunnelUrl($id, $url);
            } else {
                $url = $this->cachedTunnelUrl($id);
            }

            return [
                'ok' => $url !== null,
                'tunnel_url' => $url,
                'error' => $url !== null ? null : 'Tunnel URL not found in cloudflared logs.',
            ];
        }

        $res = $this->dockerPost("/containers/{$id}/start");

        if (! $res['ok']) {
            return [
                'ok' => false,
                'tunnel_url' => null,
                'error' => 'Docker API error ('.$res['code'].')'.($res['body'] ? ': '.$res['body'] : '.'),
            ];
        }

        // Confirm it's running after start/restart.
        $isRunning = false;
        for ($i = 0; $i < 8; $i++) {
            usleep(200_000);
            $fresh = $this->getContainerByName(self::CONTAINER_NAME);
            $isRunning = (bool) (($fresh['State']['Running'] ?? false));
            if ($isRunning) {
                break;
            }
        }

        if (! $isRunning) {
            return [
                'ok' => false,
                'tunnel_url' => null,
                'error' => 'cloudflared failed to start (container not running).',
            ];
        }

        // Tunnel URL may take a moment to appear in logs.
        $url = null;
        for ($i = 0; $i < 10; $i++) {
            $url = $this->extractTryCloudflareUrlFromLogs($id, $startedAtUnix);
            if (is_string($url) && trim($url) !== '') {
                break;
            }
            usleep(250_000);
        }
        if (is_string($url) && trim($url) !== '') {
            $this->cacheTunnelUrl($id, $url);
        }

        return [
            'ok' => $url !== null,
            'tunnel_url' => $url,
            'error' => $url !== null ? null : 'Tunnel URL not found in cloudflared logs.',
        ];
    }

    private function cachedTunnelUrl(string $containerId): ?string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::URL_CACHE_PATH)) {
            return null;
        }

        $raw = (string) $disk->get(self::URL_CACHE_PATH);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $url = $decoded[$containerId]['tunnel_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    private function cacheTunnelUrl(string $containerId, string $tunnelUrl): void
    {
        $tunnelUrl = trim($tunnelUrl);
        if ($containerId === '' || $tunnelUrl === '') {
            return;
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory('shopify');

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
            'cached_at' => now()->toISOString(),
        ];

        $disk->put(self::URL_CACHE_PATH, json_encode($data));
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
            return ['ok' => false, 'error' => 'cloudflared container not found.'];
        }

        $id = (string) ($container['Id'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'error' => 'cloudflared container id missing.'];
        }

        $res = $this->dockerPost("/containers/{$id}/stop?t=5");
        if (! $res['ok'] && $res['code'] !== 304) {
            return [
                'ok' => false,
                'error' => 'Docker API error ('.$res['code'].')'.($res['body'] ? ': '.$res['body'] : '.'),
            ];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getContainerByName(string $name): ?array
    {
        $json = $this->dockerGet('/containers/'.rawurlencode($name).'/json');
        if ($json === null) {
            return null;
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true) ?? [];
        if ($decoded === []) {
            return null;
        }

        return $decoded;
    }

    private function extractTryCloudflareUrlFromLogs(string $containerId, ?int $startedAtUnix): ?string
    {
        // Cloudflared prints the URL once; keep a small tail fast, but fall back to a larger tail
        // if we don't find it (e.g. after the logs fill up with proxy errors).
        foreach ([200, 5000] as $tail) {
            $raw = $this->dockerGet("/containers/{$containerId}/logs?stdout=1&stderr=1&tail={$tail}");
            if ($raw === null) {
                continue;
            }

            if (preg_match_all('~https://[a-z0-9-]+\\.trycloudflare\\.com~i', $raw, $m) >= 1) {
                $all = $m[0] ?? [];
                $last = is_array($all) ? end($all) : null;

                return is_string($last) ? $last : null;
            }
        }

        // As a last resort, fetch only the startup window.
        // This is small and reliably contains the printed URL.
        if (is_int($startedAtUnix) && $startedAtUnix > 0) {
            $until = $startedAtUnix + 180;
            $raw = $this->dockerGet("/containers/{$containerId}/logs?stdout=1&stderr=1&since={$startedAtUnix}&until={$until}");
            if ($raw !== null && preg_match_all('~https://[a-z0-9-]+\\.trycloudflare\\.com~i', $raw, $m) >= 1) {
                $all = $m[0] ?? [];
                $last = is_array($all) ? end($all) : null;

                return is_string($last) ? $last : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $container
     */
    private function containerStartedAtUnix(array $container): ?int
    {
        $startedAt = $container['State']['StartedAt'] ?? null;
        if (! is_string($startedAt) || trim($startedAt) === '') {
            return null;
        }

        $ts = strtotime($startedAt);

        return is_int($ts) && $ts > 0 ? $ts : null;
    }

    private function dockerGet(string $path): ?string
    {
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, self::DOCKER_SOCKET);
        curl_setopt($ch, CURLOPT_URL, 'http://localhost'.$path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($out === false || $code >= 400) {
            return null;
        }

        return (string) $out;
    }

    /**
     * @return array{ok:bool, code:int, body:string|null}
     */
    private function dockerPost(string $path): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return ['ok' => false, 'code' => 0, 'body' => null];
        }

        curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, self::DOCKER_SOCKET);
        curl_setopt($ch, CURLOPT_URL, 'http://localhost'.$path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        // Docker Engine API expects no request body for start/stop/restart endpoints.
        // Some cURL builds can otherwise send a non-empty body, which newer API versions reject.
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Length: 0']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = is_string($out) && trim($out) !== '' ? $out : null;

        return [
            'ok' => $code >= 200 && $code < 300,
            'code' => $code,
            'body' => $body,
        ];
    }
}
