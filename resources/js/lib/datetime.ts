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

export function formatPoMetaDate(ymdOrIso: string | null | undefined): string {
    if (!ymdOrIso) return '—';
    const s = String(ymdOrIso).trim();
    if (s === '') return '—';
    const d = /^\d{4}-\d{2}-\d{2}$/.test(s) ? new Date(`${s}T12:00:00`) : new Date(s);
    if (Number.isNaN(d.getTime())) return '—';

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
    }).format(d);
}

/** Activity row timestamps like the PO beta mock ("Today 4:42 PM"). */
export function formatPoActivityTimestamp(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';

    const now = new Date();
    const time = new Intl.DateTimeFormat('en-US', {
        hour: 'numeric',
        minute: '2-digit',
    }).format(d);

    if (d.toDateString() === now.toDateString()) {
        return `Today ${time}`;
    }

    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (d.toDateString() === yesterday.toDateString()) {
        return `Yesterday ${time}`;
    }

    return formatTorontoDateTime(iso);
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
    const s = String(iso).trim();
    if (s === '') return '—';
    // API calendar dates (Y-m-d) are date-only — parse at local noon to avoid UTC day shifts.
    const d = /^\d{4}-\d{2}-\d{2}$/.test(s) ? new Date(`${s}T12:00:00`) : new Date(s);
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
