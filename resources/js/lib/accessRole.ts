export type AccessRole = 'admin' | 'employee';

export function currentAccessRole(): AccessRole {
    if (typeof document === 'undefined') return 'admin';
    const meta = document.querySelector('meta[name="external-access-role"]');
    const raw = (meta?.getAttribute('content') ?? '').trim().toLowerCase();
    if (raw === 'employee') return 'employee';

    return 'admin';
}

