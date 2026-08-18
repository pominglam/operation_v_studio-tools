import { describe, expect, it } from 'vitest';
import { build17TrackUrl, parseShipmentTrackingNumbers } from '../shipmentTracking';

describe('build17TrackUrl', () => {
    it('builds a carrier-auto-detect 17TRACK link', () => {
        expect(build17TrackUrl(' 1Z999 AA10123456784 ')).toBe(
            'https://t.17track.net/en#nums=1Z999%20AA10123456784',
        );
    });

    it('returns null when no tracking number is available', () => {
        expect(build17TrackUrl(null)).toBeNull();
        expect(build17TrackUrl('   ')).toBeNull();
    });

    it('parses, trims, and deduplicates newline or comma-separated tracking numbers', () => {
        expect(
            parseShipmentTrackingNumbers('1Z999AA10123456784\nRR123456789CN, 1Z999AA10123456784'),
        ).toEqual(['1Z999AA10123456784', 'RR123456789CN']);
    });
});
