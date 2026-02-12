<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\DAL\RuntimeSettings\RuntimeSettingRepository;

final class ExternalAccessSettingsService
{
    private const string KEY_ENABLED = 'external_access.enabled';

    public function __construct(
        private readonly RuntimeSettingRepository $settings,
    ) {}

    public function isEnabled(): bool
    {
        $raw = $this->settings->getString(self::KEY_ENABLED);
        if (! is_string($raw) || trim($raw) === '') return false;
        $v = strtolower(trim($raw));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->putString(self::KEY_ENABLED, $enabled ? 'true' : 'false');
    }
}

