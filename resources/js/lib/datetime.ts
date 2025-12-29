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

export function formatTorontoEpochSeconds(epochSeconds: number | null | undefined): string {
  if (epochSeconds === null || epochSeconds === undefined) return '—';
  if (!Number.isFinite(epochSeconds)) return String(epochSeconds);
  return formatTorontoDateTime(new Date(epochSeconds * 1000).toISOString());
}


