<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

final class ExternalAccessAuthService
{
    public const string COOKIE_NAME = 'external_access_auth';

    public function isPasswordConfigured(): bool
    {
        $pw = $this->password();
        return is_string($pw) && trim($pw) !== '';
    }

    public function verifyPassword(string $candidate): bool
    {
        $pw = $this->password();
        if (! is_string($pw) || trim($pw) === '') return false;

        // timing-safe compare
        return hash_equals($pw, $candidate);
    }

    public function expectedCookieValue(): ?string
    {
        $pw = $this->password();
        $key = (string) config('app.key');
        $pw = is_string($pw) ? trim($pw) : '';
        $key = trim($key);

        if ($pw === '' || $key === '') return null;
        return hash_hmac('sha256', $pw, $key);
    }

    public function isAuthorizedCookie(?string $cookieValue): bool
    {
        $expected = $this->expectedCookieValue();
        if (! is_string($expected) || $expected === '') return false;
        if (! is_string($cookieValue) || trim($cookieValue) === '') return false;
        return hash_equals($expected, trim($cookieValue));
    }

    private function password(): ?string
    {
        $pw = config('app.external_access_password');
        return is_string($pw) ? $pw : null;
    }
}

