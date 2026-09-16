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

/** Toronto datetime without timezone suffix (e.g. for dense table columns). */
export function formatTorontoDateTimeCompact(iso: string | null | undefined): string {
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
        hour12: false,
    }).format(d);
}

/** Calendar day before a Y-m-d date (local noon parse). Empty when the source is missing. */
export function dayBeforeYmd(ymd: string | null | undefined): string {
    if (!ymd) {
        return '';
    }
    const s = String(ymd).trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) {
        return '';
    }
    const d = new Date(`${s}T12:00:00`);
    if (Number.isNaN(d.getTime())) {
        return '';
    }
    d.setDate(d.getDate() - 1);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

export function calendarMonthsBetween(
    fromYmd: string | null | undefined,
    toYmd: string | null | undefined,
): number | null {
    const from = ymdYearMonth(fromYmd);
    const to = ymdYearMonth(toYmd);
    if (from === null || to === null) {
        return null;
    }

    return to.year * 12 + to.month - (from.year * 12 + from.month);
}

function ymdYearMonth(ymd: string | null | undefined): { year: number; month: number } | null {
    const match = /^(\d{4})-(\d{2})/.exec(String(ymd ?? '').trim());
    if (match === null) {
        return null;
    }

    return { year: Number(match[1]), month: Number(match[2]) };
}

export function torontoTodayYmd(): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Toronto',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
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
