const REJECTION_SIGNALS = [
    /quota\s+exceeded/i,
    /track(?:ing)?\s+limit/i,
    /captcha/i,
    /verify\s+(?:that\s+)?you\s+are\s+(?:a\s+)?human/i,
    /no\s+tracking\s+(?:information|details|events|result)/i,
    /tracking\s+(?:information|details)\s+(?:is\s+)?not\s+(?:available|found)/i,
    /could(?:n't| not)\s+find/i,
    /invalid\s+tracking\s+number/i,
    /抱歉，暂无此快递/,
    /暂无物流轨迹/,
    /no information about your package/i,
    /couldn't find any tracking/i,
    /暂无查询结果/,
];

const STATUS_SIGNAL =
    /\b(delivered|in transit|out for delivery|label (?:created|information)|electronically submitted|shipment information|picked up|departed|arrived|customs|exception|available for pickup)\b/i;

const DATE_PATTERNS = [
    /\b20\d{2}[-/.]\d{1,2}[-/.]\d{1,2}(?:[ T,]+\d{1,2}:\d{2})?/g,
    /\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+20\d{2}(?:,?\s+\d{1,2}:\d{2})?/gi,
    /\b\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\s+20\d{2}(?:,?\s+\d{1,2}:\d{2})?/gi,
];

function countEventDates(bodyText) {
    const values = new Set();
    for (const pattern of DATE_PATTERNS) {
        for (const match of bodyText.matchAll(pattern)) {
            values.add(match[0].toLowerCase());
        }
    }
    return values.size;
}

function classifyTrackingPage({ trackingNumber, bodyText, requireTrackingNumber = true }) {
    const text = String(bodyText || '')
        .replace(/\s+/g, ' ')
        .trim();
    if (requireTrackingNumber && !text.includes(trackingNumber)) {
        return { matched: false, eventCount: 0, reason: 'tracking_number_missing' };
    }
    if (REJECTION_SIGNALS.some((signal) => signal.test(text))) {
        return { matched: false, eventCount: 0, reason: 'rejected_page' };
    }

    const eventCount = countEventDates(text);
    const matched = eventCount >= 2 && STATUS_SIGNAL.test(text);

    return {
        matched,
        eventCount,
        reason: matched ? 'events' : 'insufficient_event_evidence',
    };
}

module.exports = { classifyTrackingPage };
