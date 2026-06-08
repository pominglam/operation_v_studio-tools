<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\DAL\RuntimeSettings\RuntimeSettingRepository;

final class PlamodPreorderSettingsService
{
    public const string RUNTIME_KEY = 'plamod_preorder.excluded_categories';

    public function __construct(
        private readonly RuntimeSettingRepository $settings,
    ) {}

    /**
     * @return array{excluded_categories: array<int, string>}
     */
    public function get(): array
    {
        $raw = $this->settings->getString(self::RUNTIME_KEY);
        if ($raw === null || trim($raw) === '') {
            return ['excluded_categories' => []];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return ['excluded_categories' => []];
        }

        $categories = [];
        foreach ($decoded as $item) {
            if (! is_string($item)) {
                continue;
            }
            $v = trim($item);
            if ($v !== '') {
                $categories[] = $v;
            }
        }

        return ['excluded_categories' => array_values(array_unique($categories))];
    }

    /**
     * @param  array<int, string>  $categories
     * @return array{excluded_categories: array<int, string>}
     */
    public function save(array $categories): array
    {
        $normalized = [];
        foreach ($categories as $category) {
            if (! is_string($category)) {
                continue;
            }
            $v = trim($category);
            if ($v !== '') {
                $normalized[] = $v;
            }
        }

        $normalized = array_values(array_unique($normalized));
        $this->settings->putString(self::RUNTIME_KEY, json_encode($normalized, JSON_THROW_ON_ERROR));

        return ['excluded_categories' => $normalized];
    }
}
