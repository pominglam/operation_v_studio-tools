<?php

declare(strict_types=1);

namespace App\DAL\RuntimeSettings;

interface RuntimeSettingRepository
{
    public function getString(string $key): ?string;

    public function putString(string $key, ?string $value): void;
}
