const test = require('node:test');
const assert = require('node:assert/strict');

const { classifyTrackingPage } = require('../src/provider-signals');

test('accepts a shipment timeline with multiple dated events', () => {
    const result = classifyTrackingPage({
        trackingNumber: '520704842993',
        bodyText: `
      Tracking number 520704842993
      Delivered
      2026-08-18 14:32 Delivered to recipient
      2026-08-18 08:10 Out for delivery
      2026-08-17 20:22 In transit
    `,
    });

    assert.equal(result.matched, true);
    assert.equal(result.eventCount, 3);
});

test('accepts a Kuaidi100 result timeline whose page omits the queried number', () => {
    const result = classifyTrackingPage({
        trackingNumber: '520704842993',
        requireTrackingNumber: false,
        bodyText: `
      UBI Australia 官网
      时间 地点和跟踪进度
      2026.08.19 00:02 [PUROLATOR] LABEL INFORMATION ELECTRONICALLY SUBMITTED
      2026.08.18 17:32 SHIPPING INFORMATION RECEIVED
    `,
    });

    assert.equal(result.matched, true);
    assert.equal(result.eventCount, 2);
});

test('rejects generic marketing, quota, captcha, and no-result pages', () => {
  const examples = [
    'Global package tracking. Support 1,400 carriers worldwide.',
    'Quota exceeded. You have reached your track limit.',
    'Please verify you are human. CAPTCHA',
    'Tracking number 520701651454. No tracking information found.',
    '抱歉，暂无此快递 暂无物流轨迹',
    'No information about your package. Why is my parcel not tracking?',
    "Sorry, we couldn't find any tracking information for this number.",
  ];

    for (const bodyText of examples) {
        assert.equal(
            classifyTrackingPage({ trackingNumber: '520701651454', bodyText }).matched,
            false,
        );
    }
});
