import { onUnmounted, ref } from 'vue';
import { api } from '../lib/api';

export type TrackingResolution = {
    tracking_number: string;
    status: 'queued' | 'resolving' | 'resolved' | 'not_found' | 'failed';
    provider: string | null;
    tracking_url: string | null;
    retry_after: string | null;
};

export function trackingKey(trackingNumber: string): string {
    return trackingNumber.replace(/\s+/g, '').toUpperCase();
}

export function useShipmentTrackingResolution() {
    const trackingResolutions = ref<Record<string, TrackingResolution>>({});
    let trackingPollTimer: ReturnType<typeof setTimeout> | null = null;

    function resolutionFor(trackingNumber: string): TrackingResolution | null {
        return trackingResolutions.value[trackingKey(trackingNumber)] ?? null;
    }

    function isTrackingPending(trackingNumber: string): boolean {
        const status = resolutionFor(trackingNumber)?.status;
        return status === undefined || status === 'queued' || status === 'resolving';
    }

    function applyTrackingResolutions(resolutions: TrackingResolution[]): void {
        const next = { ...trackingResolutions.value };
        for (const resolution of resolutions) {
            next[trackingKey(resolution.tracking_number)] = resolution;
        }
        trackingResolutions.value = next;
    }

    function scheduleTrackingPoll(trackingNumbers: string[]): void {
        if (trackingPollTimer !== null) clearTimeout(trackingPollTimer);
        const hasPending = trackingNumbers.some(isTrackingPending);
        if (!hasPending) {
            trackingPollTimer = null;
            return;
        }
        trackingPollTimer = setTimeout(() => void resolveTrackingNumbers(trackingNumbers), 2000);
    }

    async function resolveTrackingNumbers(trackingNumbers: string[]): Promise<void> {
        const unique = [
            ...new Map(
                trackingNumbers
                    .map((number) => number.trim())
                    .filter((value) => value !== '')
                    .map((number) => [trackingKey(number), number] as const),
            ).values(),
        ];
        if (unique.length === 0) return;

        const queued = unique
            .filter((number) => resolutionFor(number) === null)
            .map<TrackingResolution>((number) => ({
                tracking_number: number,
                status: 'queued',
                provider: null,
                tracking_url: null,
                retry_after: null,
            }));
        applyTrackingResolutions(queued);

        try {
            const response = await api.post<{ data: TrackingResolution[] }>(
                '/api/v1/shipment-tracking/resolutions',
                { tracking_numbers: unique },
            );
            applyTrackingResolutions(response.data.data);
        } catch {
            applyTrackingResolutions(
                unique.map((number) => ({
                    tracking_number: number,
                    status: 'failed',
                    provider: null,
                    tracking_url: null,
                    retry_after: null,
                })),
            );
        } finally {
            scheduleTrackingPoll(unique);
        }
    }

    function cleanup(): void {
        if (trackingPollTimer !== null) {
            clearTimeout(trackingPollTimer);
            trackingPollTimer = null;
        }
    }

    onUnmounted(cleanup);

    return {
        resolutionFor,
        isTrackingPending,
        resolveTrackingNumbers,
        cleanup,
    };
}
