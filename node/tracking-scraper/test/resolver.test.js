const test = require('node:test');
const assert = require('node:assert/strict');

const { resolveWithProviders } = require('../src/resolver');

test('returns the first provider with real tracking events', async () => {
    const attempts = [];
    const providers = [
        {
            key: 'aftership',
            probe: async () => {
                attempts.push('aftership');
                return { matched: false, reason: 'quota' };
            },
        },
        {
            key: 'kuaidi100',
            probe: async () => {
                attempts.push('kuaidi100');
                return {
                    matched: true,
                    trackingUrl: 'https://www.kuaidi100.com/?nu=520704842993',
                    eventCount: 4,
                };
            },
        },
        {
            key: 'parcelsapp',
            probe: async () => {
                attempts.push('parcelsapp');
                return { matched: true, trackingUrl: 'https://parcelsapp.com/en/tracking/example' };
            },
        },
    ];

    const result = await resolveWithProviders({
        trackingNumber: '520704842993',
        providers,
    });

    assert.deepEqual(attempts, ['aftership', 'kuaidi100']);
    assert.deepEqual(result, {
        status: 'resolved',
        provider: 'kuaidi100',
        tracking_url: 'https://www.kuaidi100.com/?nu=520704842993',
        event_count: 4,
    });
});

test('returns not_found when providers respond but none has shipment events', async () => {
    const result = await resolveWithProviders({
        trackingNumber: '520701651454',
        providers: [
            { key: 'aftership', probe: async () => ({ matched: false, reason: 'not_found' }) },
            { key: 'kuaidi100', probe: async () => ({ matched: false, reason: 'not_found' }) },
        ],
    });

    assert.deepEqual(result, {
        status: 'not_found',
        provider: null,
        tracking_url: null,
        event_count: 0,
    });
});

test('continues after one provider throws and reports failure only when all providers fail', async () => {
    const recovered = await resolveWithProviders({
        trackingNumber: '520704842993',
        providers: [
            {
                key: 'aftership',
                probe: async () => {
                    throw new Error('captcha');
                },
            },
            {
                key: 'kuaidi100',
                probe: async () => ({
                    matched: true,
                    trackingUrl: 'https://www.kuaidi100.com/?nu=520704842993',
                    eventCount: 2,
                }),
            },
        ],
    });

    assert.equal(recovered.status, 'resolved');
    assert.equal(recovered.provider, 'kuaidi100');

    const failed = await resolveWithProviders({
        trackingNumber: 'UNKNOWN',
        providers: [
            {
                key: 'aftership',
                probe: async () => {
                    throw new Error('captcha');
                },
            },
        ],
    });

    assert.equal(failed.status, 'failed');
    assert.match(failed.error_message, /aftership: captcha/);
});
