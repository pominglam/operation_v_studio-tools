/** Date-only string (Y-m-d) or ISO; avoids UTC day shifts for calendar dates. */
export function formatLocalDate(ymdOrIso: string | null | undefined): string {
    if (!ymdOrIso) return '—';
    const s = String(ymdOrIso).trim();
    if (s === '') return '—';
    const d = /^\d{4}-\d{2}-\d{2}$/.test(s) ? new Date(`${s}T12:00:00`) : new Date(s);
    if (Number.isNaN(d.getTime())) return '—';

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    }).format(d);
}

export function formatLocalDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(d);
}

export function formatTorontoDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';

    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Toronto',
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        timeZoneName: 'short',
        hour12: false,
    }).format(d);
}

export function formatTorontoDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';

    // en-CA yields YYYY-MM-DD, and we pin to America/Toronto to match backend timezone.
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Toronto',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(d);
}

export function formatTorontoEpochSeconds(epochSeconds: number | null | undefined): string {
    if (epochSeconds === null || epochSeconds === undefined) return '—';
    if (!Number.isFinite(epochSeconds)) return String(epochSeconds);
    return formatTorontoDateTime(new Date(epochSeconds * 1000).toISOString());
}
