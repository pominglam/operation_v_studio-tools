<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use Illuminate\Support\Facades\Storage;

final class PlamodPreorderExportProgressReader
{
    public const string STORAGE_PATH = 'plamod/preorder_export_progress.json';

    /**
     * @return array{ok: bool, active: bool, phase?: string, filters_total?: int, filters_processed?: int, current_filter?: string, pdp_enrich_done?: int, pdp_enrich_total?: int, rows_merged?: int, error_message?: string}
     */
    public function read(): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::STORAGE_PATH)) {
            return [
                'ok' => true,
                'active' => false,
            ];
        }

        try {
            $raw = $disk->get(self::STORAGE_PATH);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'active' => false,
                'error_message' => $e->getMessage(),
            ];
        }

        if (! is_string($raw) || $raw === '') {
            return [
                'ok' => true,
                'active' => false,
            ];
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return [
                'ok' => false,
                'active' => false,
                'error_message' => 'Invalid preorder export progress file.',
            ];
        }

        return $this->normalize($data);
    }

    /**
     * @param  array<mixed>  $data
     * @return array{ok: bool, active: bool, phase?: string, filters_total?: int, filters_processed?: int, current_filter?: string, pdp_enrich_done?: int, pdp_enrich_total?: int, rows_merged?: int, error_message?: string}
     */
    private function normalize(array $data): array
    {
        $payload = [
            'ok' => true,
            'active' => (bool) ($data['active'] ?? false),
        ];

        if (isset($data['phase']) && is_string($data['phase']) && $data['phase'] !== '') {
            $payload['phase'] = $data['phase'];
        }
        if (isset($data['current_filter']) && is_string($data['current_filter'])) {
            $payload['current_filter'] = $data['current_filter'];
        }
        foreach (['filters_total', 'filters_processed', 'pdp_enrich_done', 'pdp_enrich_total', 'rows_merged'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $payload[$key] = (int) $data[$key];
            }
        }

        return $payload;
    }
}
