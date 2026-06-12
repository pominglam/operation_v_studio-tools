<?php

declare(strict_types=1);

namespace App\Services\Plamod;

final class PlamodPreorderSyncFailureKind
{
    public static function classify(string $message): string
    {
        $lower = mb_strtolower($message);

        if (
            str_contains($lower, 'login')
            || str_contains($lower, 'sign-in')
            || str_contains($lower, 'sign in')
        ) {
            return 'login';
        }

        if (
            str_contains($lower, 'timeout')
            || str_contains($lower, 'curl error 28')
            || str_contains($lower, 'operation timed out')
        ) {
            return 'timeout';
        }

        if (
            str_contains($lower, 'connection')
            || str_contains($lower, 'unreachable')
            || str_contains($lower, 'connectionexception')
        ) {
            return 'connection';
        }

        return 'other';
    }

    public static function isRetryable(string $message): bool
    {
        return in_array(self::classify($message), ['login', 'timeout', 'connection'], true);
    }
}
