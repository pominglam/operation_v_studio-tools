<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

final class ExternalAccessAuthService
{
    public const string COOKIE_NAME = 'external_access_auth';

    public const string ROLE_ADMIN = 'admin';

    public const string ROLE_EMPLOYEE = 'employee';

    public function isPasswordConfigured(): bool
    {
        return $this->adminPassword() !== null || $this->employeePassword() !== null;
    }

    public function resolveRoleForPassword(string $candidate): ?string
    {
        $employee = $this->employeePassword();
        if ($employee !== null && hash_equals($employee, $candidate)) {
            return self::ROLE_EMPLOYEE;
        }

        $admin = $this->adminPassword();
        if ($admin !== null && hash_equals($admin, $candidate)) {
            return self::ROLE_ADMIN;
        }

        return null;
    }

    public function cookieValueForRole(string $role): ?string
    {
        $role = $this->normalizeRole($role);
        if ($role === null) {
            return null;
        }

        $pw = $role === self::ROLE_EMPLOYEE ? $this->employeePassword() : $this->adminPassword();
        $key = (string) config('app.key');
        $pw = $pw ?? '';
        $key = trim($key);

        if ($pw === '' || $key === '') {
            return null;
        }

        $sig = hash_hmac('sha256', "{$role}:{$pw}", $key);

        return "{$role}|{$sig}";
    }

    public function resolveAuthorizedRole(?string $cookieValue): ?string
    {
        if (! is_string($cookieValue) || trim($cookieValue) === '') {
            return null;
        }

        $cookieValue = trim($cookieValue);
        $parts = explode('|', $cookieValue, 2);
        if (count($parts) === 2) {
            $role = $this->normalizeRole($parts[0] ?? null);
            $sig = trim((string) ($parts[1] ?? ''));
            if ($role !== null && $sig !== '') {
                $expected = $this->cookieValueForRole($role);
                if (is_string($expected) && $expected !== '' && hash_equals($expected, "{$role}|{$sig}")) {
                    return $role;
                }
            }
        }

        // Backward compatibility: legacy cookie contained only admin signature.
        $legacy = $this->legacyAdminCookieValue();
        if ($legacy !== null && hash_equals($legacy, $cookieValue)) {
            return self::ROLE_ADMIN;
        }

        return null;
    }

    public function isAuthorizedCookie(?string $cookieValue): bool
    {
        return $this->resolveAuthorizedRole($cookieValue) !== null;
    }

    public function expectedCookieValue(): ?string
    {
        return $this->cookieValueForRole(self::ROLE_ADMIN);
    }

    public function isEmployeeRole(?string $role): bool
    {
        return $this->normalizeRole($role) === self::ROLE_EMPLOYEE;
    }

    public function isAdminRole(?string $role): bool
    {
        return $this->normalizeRole($role) === self::ROLE_ADMIN;
    }

    private function normalizeRole(?string $role): ?string
    {
        $role = is_string($role) ? strtolower(trim($role)) : '';
        if ($role === self::ROLE_ADMIN) {
            return self::ROLE_ADMIN;
        }
        if ($role === self::ROLE_EMPLOYEE) {
            return self::ROLE_EMPLOYEE;
        }

        return null;
    }

    private function legacyAdminCookieValue(): ?string
    {
        $pw = $this->adminPassword();
        $key = trim((string) config('app.key'));
        if ($pw === null || $key === '') {
            return null;
        }

        return hash_hmac('sha256', $pw, $key);
    }

    private function adminPassword(): ?string
    {
        $pw = config('app.external_access_password');
        $pw = is_string($pw) ? trim($pw) : '';

        return $pw !== '' ? $pw : null;
    }

    private function employeePassword(): ?string
    {
        $pw = config('app.external_access_employee_password');
        $pw = is_string($pw) ? trim($pw) : '';

        return $pw !== '' ? $pw : null;
    }
}
