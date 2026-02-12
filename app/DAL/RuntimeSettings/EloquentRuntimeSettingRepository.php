<?php

declare(strict_types=1);

namespace App\DAL\RuntimeSettings;

use App\Models\AppRuntimeSetting;

final class EloquentRuntimeSettingRepository implements RuntimeSettingRepository
{
    public function getString(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') return null;

        /** @var string|null $v */
        $v = AppRuntimeSetting::query()
            ->where('key', '=', $key)
            ->value('value');

        $v = is_string($v) ? trim($v) : '';
        return $v !== '' ? $v : null;
    }

    public function putString(string $key, ?string $value): void
    {
        $key = trim($key);
        if ($key === '') return;

        $v = is_string($value) ? trim($value) : null;
        if ($v === '') $v = null;

        AppRuntimeSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $v],
        );
    }
}

