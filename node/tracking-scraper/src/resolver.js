function cleanErrorMessage(error) {
    const message = String(error?.message || error || 'Unknown provider error');
    return message.replace(/\s+/g, ' ').trim().slice(0, 300);
}

async function resolveWithProviders({ trackingNumber, providers }) {
    const errors = [];
    let completedProbe = false;

    for (const provider of providers) {
        try {
            const result = await provider.probe(trackingNumber);
            completedProbe = true;
            if (result?.matched && result.trackingUrl) {
                return {
                    status: 'resolved',
                    provider: provider.key,
                    tracking_url: result.trackingUrl,
                    event_count: Number(result.eventCount || 0),
                };
            }
        } catch (error) {
            errors.push(`${provider.key}: ${cleanErrorMessage(error)}`);
        }
    }

    if (completedProbe) {
        return {
            status: 'not_found',
            provider: null,
            tracking_url: null,
            event_count: 0,
        };
    }

    return {
        status: 'failed',
        provider: null,
        tracking_url: null,
        event_count: 0,
        error_message: errors.join('; ') || 'No tracking providers were available.',
    };
}

module.exports = { resolveWithProviders };
