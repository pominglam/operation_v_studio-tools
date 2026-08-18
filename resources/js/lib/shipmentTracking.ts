const SEVENTEEN_TRACK_BASE_URL = 'https://t.17track.net/en#nums=';

export function parseShipmentTrackingNumbers(value: string): string[] {
    return [
        ...new Set(
            value
                .split(/[\n,]+/)
                .map((number) => number.trim())
                .filter(Boolean),
        ),
    ];
}

export function build17TrackUrl(trackingNumber: string | null | undefined): string | null {
    const normalized = trackingNumber?.trim() ?? '';
    if (normalized === '') {
        return null;
    }

    return `${SEVENTEEN_TRACK_BASE_URL}${encodeURIComponent(normalized)}`;
}
