const { classifyTrackingPage } = require('./provider-signals');

async function readTrackingPage(
    page,
    url,
    trackingNumber,
    {
        requireTrackingNumber = true,
        timelineStart = null,
        timelineEnd = null,
        readyHints = [],
    } = {},
) {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    const hints = [trackingNumber, '时间 地点和跟踪进度', ...readyHints];
    await page
        .waitForFunction(
            ({ hintList }) => {
                const text = document.body?.innerText || '';
                const hasHint = hintList.some((hint) => text.includes(hint));
                return (
                    hasHint &&
                    /(delivered|in transit|out for delivery|information received|no tracking|not found|quota|captcha|跟踪进度|17track|track your package)/i.test(
                        text,
                    )
                );
            },
            { hintList: hints },
            { timeout: 20_000 },
        )
        .catch(() => {});

    let bodyText = await page.locator('body').innerText({ timeout: 5_000 });
    if (timelineStart && bodyText.includes(timelineStart)) {
        bodyText = bodyText.slice(bodyText.indexOf(timelineStart));
        if (timelineEnd && bodyText.includes(timelineEnd)) {
            bodyText = bodyText.slice(0, bodyText.indexOf(timelineEnd));
        }
    }
    return classifyTrackingPage({ trackingNumber, bodyText, requireTrackingNumber });
}

function createProvider({ key, buildUrl, browser, pageOptions = {} }) {
    return {
        key,
        async probe(trackingNumber) {
            const context = await browser.newContext({
                locale: 'en-CA',
                timezoneId: 'America/Toronto',
                viewport: { width: 1280, height: 900 },
            });
            const page = await context.newPage();
            await page.route('**/*', async (route) => {
                const type = route.request().resourceType();
                if (['image', 'media', 'font'].includes(type)) {
                    await route.abort();
                    return;
                }
                await route.continue();
            });

            const trackingUrl = buildUrl(trackingNumber);
            try {
                const result = await readTrackingPage(
                    page,
                    trackingUrl,
                    trackingNumber,
                    pageOptions,
                );
                return { ...result, trackingUrl };
            } finally {
                await context.close();
            }
        },
    };
}

function createProviders(browser) {
    return [
        createProvider({
            key: '17track',
            browser,
            buildUrl: (number) => `https://t.17track.net/en#nums=${encodeURIComponent(number)}`,
            pageOptions: {
                readyHints: ['17TRACK', 'Tracking results'],
            },
        }),
        createProvider({
            key: 'kuaidi100',
            browser,
            buildUrl: (number) => `https://www.kuaidi100.com/?nu=${encodeURIComponent(number)}`,
            pageOptions: {
                requireTrackingNumber: false,
                timelineStart: '时间 地点和跟踪进度',
                timelineEnd: '点击查看更多物流',
            },
        }),
        createProvider({
            key: 'aftership',
            browser,
            buildUrl: (number) => `https://www.aftership.com/track/${encodeURIComponent(number)}`,
        }),
        createProvider({
            key: 'ship24',
            browser,
            buildUrl: (number) =>
                `https://www.ship24.com/tracking?p=${encodeURIComponent(number)}`,
        }),
        createProvider({
            key: 'parcelsapp',
            browser,
            buildUrl: (number) =>
                `https://parcelsapp.com/en/tracking/${encodeURIComponent(number)}`,
        }),
    ];
}

module.exports = { createProviders, readTrackingPage };
