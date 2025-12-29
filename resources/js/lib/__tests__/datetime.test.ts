import { describe, expect, it } from 'vitest';
import { formatTorontoDateTime, formatTorontoEpochSeconds } from '../datetime';

describe('datetime', () => {
  it('formats ISO timestamps in America/Toronto', () => {
    // 2025-12-25 is winter time in Toronto (EST, UTC-5)
    const out = formatTorontoDateTime('2025-12-25T15:43:25.000000Z');
    expect(out).toContain('Dec');
    expect(out).toContain('2025');
    expect(out).toContain('10:43');
    expect(out).toMatch(/EST|GMT-5/);
  });

  it('formats epoch seconds in America/Toronto', () => {
    // Same instant as above, expressed as epoch seconds
    const out = formatTorontoEpochSeconds(1766677405);
    expect(out).toContain('Dec');
    expect(out).toContain('2025');
    expect(out).toContain('10:43');
  });

  it('returns placeholder for null/undefined/invalid', () => {
    expect(formatTorontoDateTime(null)).toBe('—');
    expect(formatTorontoDateTime(undefined)).toBe('—');
    expect(formatTorontoDateTime('not-a-date')).toBe('—');
    expect(formatTorontoEpochSeconds(null)).toBe('—');
    expect(formatTorontoEpochSeconds(undefined)).toBe('—');
  });
});


