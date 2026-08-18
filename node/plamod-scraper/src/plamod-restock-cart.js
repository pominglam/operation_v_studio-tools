const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

function storageRoot() {
    return String(
        process.env.PLAMOD_STORAGE_ROOT ||
            path.resolve(__dirname, '..', '..', '..', 'storage', 'app', 'private'),
    );
}

function restockCartProgressPath() {
    return path.join(storageRoot(), 'plamod', 'restock_cart_progress.json');
}

function writeRestockCartProgress(patch) {
    const dir = path.join(storageRoot(), 'plamod');
    fs.mkdirSync(dir, { recursive: true });
    let existing = {};
    try {
        if (fs.existsSync(restockCartProgressPath())) {
            existing = JSON.parse(fs.readFileSync(restockCartProgressPath(), 'utf8'));
        }
    } catch {
        existing = {};
    }
    const next = {
        ...existing,
        ...patch,
        active: patch.active ?? existing.active ?? true,
        updated_at: new Date().toISOString(),
    };
    fs.writeFileSync(restockCartProgressPath(), JSON.stringify(next), 'utf8');
}

function readRestockCartProgress() {
    try {
        if (!fs.existsSync(restockCartProgressPath())) {
            return { ok: true, active: false };
        }
        const data = JSON.parse(fs.readFileSync(restockCartProgressPath(), 'utf8'));
        return { ok: true, ...data, active: Boolean(data.active) };
    } catch (e) {
        return {
            ok: false,
            active: false,
            error_message: String(e?.message || 'progress read failed'),
        };
    }
}

function clearRestockCartProgress() {
    try {
        if (fs.existsSync(restockCartProgressPath())) {
            fs.unlinkSync(restockCartProgressPath());
        }
    } catch {
        // ignore
    }
}

function normalizeSku(value) {
    return String(value || '').trim();
}

function cartProfileDir() {
    return String(
        process.env.PLAMOD_RESTOCK_CART_PROFILE_DIR ||
            path.resolve(__dirname, '..', '.pw-user-data-cart'),
    );
}

function cleanupPersistentProfileLocks(dir) {
    const candidates = [
        path.join(dir, 'SingletonLock'),
        path.join(dir, 'SingletonCookie'),
        path.join(dir, 'SingletonSocket'),
        `${dir}-lock`,
        path.join(dir, '.pw-user-data-lock'),
    ];
    for (const p of candidates) {
        try {
            if (fs.existsSync(p)) {
                fs.rmSync(p, { force: true });
            }
        } catch {
            // ignore
        }
    }
}

function timingMs(name, fallback) {
    const parsed = Number.parseInt(String(process.env[name] ?? ''), 10);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
}

const CART_SETTLE_MS = timingMs('PLAMOD_RESTOCK_CART_CART_SETTLE_MS', 150);
const CART_ACTION_TIMEOUT_MS = timingMs('PLAMOD_RESTOCK_CART_ACTION_TIMEOUT_MS', 8000);

/** @type {Promise<void>} */
let cartSessionChain = Promise.resolve();

async function withCartSessionMutex(fn) {
    const run = cartSessionChain.then(fn, fn);
    cartSessionChain = run.then(
        () => undefined,
        () => undefined,
    );
    return run;
}

async function acquireCartSession(deps, baseUrl) {
    const dir = cartProfileDir();
    cleanupPersistentProfileLocks(dir);
    const context = await chromium.launchPersistentContext(dir, {
        timeout: 30_000,
        headless: true,
        acceptDownloads: true,
        viewport: { width: 1400, height: 900 },
        locale: 'en-CA',
        timezoneId: 'America/Toronto',
    });
    const page = context.pages()[0] || (await context.newPage());
    await deps.ensureLoggedInQuick(page, baseUrl, context);
    return { context, page };
}

async function replaceCartWriteSession(deps, baseUrl, currentContext) {
    await currentContext.close().catch(() => undefined);
    const session = await acquireCartSession(deps, baseUrl);
    await session.context.clearCookies();
    await session.page.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await session.page
        .evaluate(() => {
            window.localStorage.clear();
            window.sessionStorage.clear();
        })
        .catch(() => undefined);
    await deps.ensureLoggedInQuick(session.page, baseUrl, session.context);
    return session;
}

function parseMoqFromBlockText(text, comboQty = 0) {
    const normalized = String(text || '')
        .replace(/\s+/g, ' ')
        .trim();
    const detailed = normalized.match(/MOQ:\s*(\d+)\s*TOTAL0*(\d+)/i);
    if (detailed) {
        const moqRaw = Number.parseInt(detailed[1], 10);
        const lineTotal = Number.parseInt(detailed[2], 10);
        if (Number.isFinite(moqRaw) && moqRaw > 0) {
            const combo = Math.max(0, Number.parseInt(String(comboQty), 10) || 0);
            if (combo > 0) {
                const moqStr = String(moqRaw);
                const comboStr = String(combo);
                if (moqStr.endsWith(comboStr) && moqStr.length > comboStr.length) {
                    const prefix = Number.parseInt(
                        moqStr.slice(0, moqStr.length - comboStr.length),
                        10,
                    );
                    if (Number.isFinite(prefix) && prefix > 0) {
                        return prefix;
                    }
                }
            }

            if (Number.isFinite(lineTotal) && lineTotal >= 0) {
                const moqStr = String(moqRaw);
                const totalStr = String(lineTotal);
                if (moqStr.endsWith(totalStr) && moqStr.length > totalStr.length) {
                    const prefix = Number.parseInt(
                        moqStr.slice(0, moqStr.length - totalStr.length),
                        10,
                    );
                    if (Number.isFinite(prefix) && prefix > 0) {
                        return prefix;
                    }
                }
            }

            if (moqRaw <= 60 && lineTotal > 0) {
                return moqRaw;
            }
        }
    }

    const simple = normalized.match(/MOQ:\s*(\d+)/i);
    if (!simple) {
        return 1;
    }

    let moq = Number.parseInt(simple[1], 10);
    if (!Number.isFinite(moq) || moq <= 0) {
        return 1;
    }

    while (moq > 60) {
        moq = Math.floor(moq / 10);
    }

    return Math.max(1, moq);
}

async function markInStockBlock(page) {
    await page.evaluate(() => {
        for (const el of document.querySelectorAll('[data-ovs-instock-block="1"]')) {
            el.removeAttribute('data-ovs-instock-block');
        }

        const norm = (v) =>
            String(v || '')
                .replace(/\s+/g, ' ')
                .trim();
        const candidates = [...document.querySelectorAll('div, section')].filter((el) => {
            const text = norm(el.textContent || '');
            if (!/IN[- ]?STOCK/i.test(text)) {
                return false;
            }
            if (!el.querySelector('button[role="combobox"]')) {
                return false;
            }
            if (!/MOQ|TOTAL|PRICE/i.test(text)) {
                return false;
            }

            return text.length <= 600;
        });

        candidates.sort((a, b) => norm(a.textContent).length - norm(b.textContent).length);
        const block = candidates[0];
        if (block) {
            block.setAttribute('data-ovs-instock-block', '1');
        }
    });
}

function getInStockBlock(page) {
    return page.locator('[data-ovs-instock-block="1"]');
}

async function readInStockBlockState(block) {
    return block.evaluate((root) => {
        const norm = (v) =>
            String(v || '')
                .replace(/\s+/g, ' ')
                .trim();
        const text = norm(root.textContent || '');
        const comboQty = Number.parseInt(
            norm(root.querySelector('button[role="combobox"]')?.textContent || ''),
            10,
        );
        const totalMatch = text.match(/TOTAL0*(\d+)/i);
        const lineTotalQty = totalMatch ? Number.parseInt(totalMatch[1], 10) : 0;
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const textNodes = [];
        while (walker.nextNode()) {
            const value = norm(walker.currentNode.textContent || '');
            if (value !== '') textNodes.push(value);
        }
        const moqLabelIndex = textNodes.findIndex((value) => /^MOQ:\s*$/i.test(value));
        const moqQty =
            moqLabelIndex >= 0
                ? Number.parseInt(String(textNodes[moqLabelIndex + 1] || '0'), 10)
                : 0;

        return {
            text,
            comboQty: Number.isFinite(comboQty) ? comboQty : 0,
            lineTotalQty: Number.isFinite(lineTotalQty) ? lineTotalQty : 0,
            moqQty: Number.isFinite(moqQty) ? moqQty : 0,
        };
    });
}

async function waitForInStockBlock(page) {
    await markInStockBlock(page);
    const block = getInStockBlock(page);
    await block.waitFor({ state: 'visible', timeout: 12000 }).catch(() => {
        throw new Error('PLAMOD did not show an IN-STOCK quantity control for this SKU.');
    });
    await block
        .locator('button[role="combobox"]')
        .first()
        .waitFor({ state: 'visible', timeout: 8000 });
}

async function waitForCartReady(page) {
    await snapshotRetailerCart(page, { refresh: false, maxAttempts: 1 });
}

async function isPlusButton(button) {
    const paths = await button
        .locator('svg path')
        .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('d') || ''));
    return paths.includes('M5 12h14') && paths.includes('M12 5v14');
}

async function findPackPlusButton(block) {
    const buttons = block.locator('button:not([disabled])');
    for (let index = (await buttons.count()) - 1; index >= 0; index -= 1) {
        const button = buttons.nth(index);
        if ((await button.getAttribute('role')) !== 'combobox' && (await isPlusButton(button))) {
            return button;
        }
    }
    return null;
}

async function clickPackPlusAndWaitForAdd(page, plus) {
    const quantityUpdate = page.waitForResponse(
        (response) => {
            const request = response.request();
            const body = String(request.postData() || '');
            return (
                request.method() === 'POST' &&
                body.includes('"productId"') &&
                body.includes('"quantity"')
            );
        },
        { timeout: CART_ACTION_TIMEOUT_MS },
    );
    await plus.click();
    const response = await quantityUpdate;
    const responseBody = await response.text().catch(() => '');
    const responseError = responseBody.match(/"code":"ERROR","message":"([^"]+)"/i);
    if (!response.ok() || responseError) {
        throw new Error(
            responseError?.[1] ||
                `PLAMOD rejected the cart quantity update (${response.status()}).`,
        );
    }
    await page.waitForTimeout(1500);
}

async function selectExactPackQty(page, block, quantity) {
    const combo = block.locator('button[role="combobox"]').first();
    await combo.click();
    const option = page.getByRole('option', { name: String(quantity), exact: true });
    if ((await option.count()) === 0) {
        throw new Error(`PLAMOD does not offer exact PACK qty ${quantity}.`);
    }

    await option.first().click();
    await page.waitForTimeout(500);
    const selected = Number.parseInt(String((await combo.textContent()) || '0').trim(), 10);
    if (selected !== quantity) {
        throw new Error(`PLAMOD PACK selector remained at ${selected}; expected ${quantity}.`);
    }
}

async function setCartQuantityViaPackStepper(page, block, targetQty, currentCartQty) {
    // The PDP plus control increments the selected PACK value and writes that
    // incremented total to the cart. Select one below the desired final total.
    await selectExactPackQty(page, block, Math.max(0, targetQty - 1));
    const plus = await findPackPlusButton(block);
    if (!plus) {
        throw new Error('PLAMOD PACK quantity control was not found on the IN-STOCK block.');
    }

    await clickPackPlusAndWaitForAdd(page, plus);
}

function parseCartRowQty(text, comboText, comboTextParts = [], totalTextParts = []) {
    const normalized = String(text || '')
        .replace(/\s+/g, ' ')
        .trim();
    const structuralQty = [...totalTextParts, ...comboTextParts]
        .map((part) => Number.parseInt(String(part).trim(), 10))
        .find((qty) => Number.isFinite(qty) && qty > 0);
    if (structuralQty !== undefined) {
        return structuralQty;
    }

    const comboQty = Number.parseInt(String(comboText || '').trim(), 10);
    if (Number.isFinite(comboQty) && comboQty > 0) {
        return comboQty;
    }

    const totalMatch = normalized.match(/TOTAL0*(\d+)/i);
    const totalQty = totalMatch ? Number.parseInt(totalMatch[1], 10) : Number.NaN;
    if (Number.isFinite(totalQty)) {
        return totalQty;
    }

    return Number.isFinite(comboQty) ? comboQty : 0;
}

function parsePreorderArrivedQty(textParts) {
    let insideArrivedPreorder = false;
    const candidates = [];
    for (const partRaw of textParts) {
        const part = String(partRaw || '')
            .replace(/\s+/g, ' ')
            .trim();
        if (!insideArrivedPreorder) {
            insideArrivedPreorder = /PREORDER ARRIVED/i.test(part);
            continue;
        }
        if (/^ORDERED$/i.test(part)) {
            const qty = candidates.at(-1);
            return Number.isFinite(qty) ? qty : 0;
        }
        if (/^\d+$/.test(part)) {
            candidates.push(Number.parseInt(part, 10));
        }
    }

    return 0;
}

function isPreorderArrivedContainerText(text) {
    const normalized = String(text || '')
        .replace(/\s+/g, ' ')
        .trim();
    return /PREORDER ARRIVED/i.test(normalized) && /ORDERED/i.test(normalized);
}

function isSkuScopedCartContainer(targetSku, productHrefs) {
    const skus = new Set(
        productHrefs
            .map(
                (href) =>
                    String(href || '')
                        .split('?')[0]
                        .split('/')
                        .filter(Boolean)
                        .at(-1) || '',
            )
            .filter((sku) => sku !== ''),
    );
    return skus.size === 1 && skus.has(String(targetSku || ''));
}

function parseRetailerCartItemBadgeCount(text) {
    const normalized = String(text || '')
        .replace(/\s+/g, ' ')
        .trim();
    const prioritized = [
        /Cart\s*\((\d+)\)/i,
        /Checkout\s*\((\d+)\s*items?\)/i,
        /Shopping Cart[\s\S]{0,80}?(\d+)\s*items?\b/i,
    ];
    for (const pattern of prioritized) {
        const match = normalized.match(pattern);
        if (match) {
            const count = Number.parseInt(match[1], 10);
            if (Number.isFinite(count) && count >= 0) {
                return count;
            }
        }
    }

    return 0;
}

async function readRetailerCartItemBadgeCount(page) {
    return page.evaluate(() => {
        const normalized = String(document.body?.innerText || '')
            .replace(/\s+/g, ' ')
            .trim();
        const prioritized = [
            /Cart\s*\((\d+)\)/i,
            /Checkout\s*\((\d+)\s*items?\)/i,
            /Shopping Cart[\s\S]{0,80}?(\d+)\s*items?\b/i,
        ];
        for (const pattern of prioritized) {
            const match = normalized.match(pattern);
            if (match) {
                const count = Number.parseInt(match[1], 10);
                if (Number.isFinite(count) && count >= 0) {
                    return count;
                }
            }
        }

        return 0;
    });
}

async function scrollRetailerCartStep(page, distance = 1000) {
    await page
        .evaluate((requestedDistance) => {
            const step = Math.max(200, Number(requestedDistance) || 1000);
            window.scrollBy(0, step);
            document
                .querySelectorAll('[data-radix-scroll-area-viewport], .overflow-auto, main')
                .forEach((node) => {
                    if (node instanceof HTMLElement) {
                        node.scrollTop += step;
                        node.dispatchEvent(new Event('scroll', { bubbles: true }));
                    }
                });
        }, distance)
        .catch(() => undefined);
}

async function resetRetailerCartScroll(page) {
    await page
        .evaluate(() => {
            window.scrollTo(0, 0);
            document
                .querySelectorAll('[data-radix-scroll-area-viewport], .overflow-auto, main')
                .forEach((node) => {
                    if (node instanceof HTMLElement) {
                        node.scrollTop = 0;
                        node.dispatchEvent(new Event('scroll', { bubbles: true }));
                    }
                });
        })
        .catch(() => undefined);
}

async function positionRetailerCartScroll(page, ratio) {
    await page
        .evaluate((requestedRatio) => {
            const scrollable = [...document.querySelectorAll('*')]
                .filter(
                    (node) =>
                        node instanceof HTMLElement &&
                        node.scrollHeight > node.clientHeight + 100 &&
                        getComputedStyle(node).overflowY !== 'visible',
                )
                .sort((left, right) => right.scrollHeight - left.scrollHeight)[0];
            if (!(scrollable instanceof HTMLElement)) {
                return;
            }

            const normalized = Math.max(0, Math.min(1, Number(requestedRatio) || 0));
            scrollable.scrollTop = Math.round(
                (scrollable.scrollHeight - scrollable.clientHeight) * normalized,
            );
            scrollable.dispatchEvent(new Event('scroll', { bubbles: true }));
        }, ratio)
        .catch(() => undefined);
}

async function readRetailerCartScrollRatio(page) {
    return page.evaluate(() => {
        const scrollable = [...document.querySelectorAll('*')]
            .filter(
                (node) =>
                    node instanceof HTMLElement &&
                    node.scrollHeight > node.clientHeight + 100 &&
                    getComputedStyle(node).overflowY !== 'visible',
            )
            .sort((left, right) => right.scrollHeight - left.scrollHeight)[0];
        if (!(scrollable instanceof HTMLElement)) {
            return 0;
        }
        const maximum = scrollable.scrollHeight - scrollable.clientHeight;
        return maximum > 0 ? scrollable.scrollTop / maximum : 0;
    });
}

async function collectRetailerCartPositionHints(page, targetCount) {
    const hints = new Map();
    await resetRetailerCartScroll(page);
    for (let round = 0; round < 60; round += 1) {
        await page.waitForTimeout(round === 0 ? 500 : 350);
        const ratio = await readRetailerCartScrollRatio(page);
        for (const sku of Object.keys(await scrapeRetailerCartQuantities(page))) {
            if (!hints.has(sku)) {
                hints.set(sku, ratio);
            }
        }
        if (targetCount > 0 && hints.size >= targetCount) {
            break;
        }
        await scrollRetailerCartStep(page);
    }
    return hints;
}

/**
 * PLAMOD cart virtualizes rows — only a handful of SKUs exist in the DOM until the
 * cart scroll container is stepped through. Merge snapshots across scroll rounds.
 *
 * @param {import('playwright').Page} page
 * @param {{ targetCount?: number, maxRounds?: number, preorderArrived?: Record<string, number> }} [options]
 */
async function collectRetailerCartQuantities(page, options = {}) {
    const targetCount = Math.max(0, Number(options.targetCount) || 0);
    const maxRounds = Math.max(1, Number(options.maxRounds) || 60);
    const requiresFullPreorderScan = Boolean(options.preorderArrived);

    await resetRetailerCartScroll(page);

    /** @type {Record<string, number>} */
    let merged = await scrapeRetailerCartQuantities(page);
    if (options.preorderArrived) {
        Object.assign(
            options.preorderArrived,
            await scrapeRetailerCartPreorderArrivedQuantities(page),
        );
    }
    let staleRounds = 0;

    for (let round = 0; round < maxRounds; round += 1) {
        const previousCount = Object.keys(merged).length;
        if (targetCount > 0 && previousCount >= targetCount && !requiresFullPreorderScan) {
            break;
        }

        await scrollRetailerCartStep(page);
        await page.waitForTimeout(round < 3 ? 600 : 450);
        merged = { ...merged, ...(await scrapeRetailerCartQuantities(page)) };
        if (options.preorderArrived) {
            Object.assign(
                options.preorderArrived,
                await scrapeRetailerCartPreorderArrivedQuantities(page),
            );
        }

        const currentCount = Object.keys(merged).length;
        if (targetCount > 0 && currentCount >= targetCount && !requiresFullPreorderScan) {
            break;
        }

        if (currentCount === previousCount) {
            staleRounds += 1;
            if (staleRounds >= 8) {
                break;
            }
        } else {
            staleRounds = 0;
        }
    }

    return merged;
}

function cartLineAdjustmentAction(currentQty, requestedQty) {
    if (currentQty === requestedQty) {
        return 'exact';
    }

    return currentQty <= 0 ? 'create' : 'adjust';
}

function cartLineTargetInStockQty(source, requestedQty, preorderArrivedQty = 0) {
    const requested = Math.max(0, Number.parseInt(String(requestedQty), 10) || 0);
    if (String(source || '').trim() !== 'new') {
        return requested;
    }

    const preorder = Math.max(0, Number.parseInt(String(preorderArrivedQty), 10) || 0);
    return Math.max(0, requested - preorder);
}

function isRestockCartRequestedQty(value) {
    const qty = Number.parseInt(String(value), 10);
    return Number.isFinite(qty) && qty >= 0;
}

function cartLineMutationSurface(action) {
    if (action === 'create') {
        return 'pdp';
    }
    if (action === 'adjust') {
        return 'cart';
    }

    return 'none';
}

function cartLineStepperPlan(currentQty, targetQty) {
    if (currentQty === targetQty) {
        return { direction: 'none', clicks: 0 };
    }

    return {
        direction: currentQty > targetQty ? 'minus' : 'plus',
        clicks: Math.abs(currentQty - targetQty),
    };
}

function cartLineStepperSelector(direction) {
    return `button:has(svg.lucide-${direction}):not([disabled])`;
}

async function scrapeRetailerCartQuantities(page) {
    return page.evaluate(() => {
        const norm = (v) =>
            String(v || '')
                .replace(/\s+/g, ' ')
                .trim();
        const skuFromHref = (href) => {
            const clean = String(href || '').split('?')[0];
            const parts = clean.split('/').filter(Boolean);
            const sku = parts[parts.length - 1] || '';
            return /^[0-9A-Za-z_-]+$/.test(sku) ? sku : '';
        };
        const numericTextParts = (element) => {
            if (!element) return [];
            const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
            const values = [];
            while (walker.nextNode()) {
                const value = norm(walker.currentNode.textContent || '');
                if (/^\d+$/.test(value)) values.push(value);
            }
            return values;
        };
        const numericTextPartsAfterTotal = (element) => {
            if (!element) return [];
            const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
            let foundTotal = false;
            const values = [];
            while (walker.nextNode()) {
                const value = norm(walker.currentNode.textContent || '');
                if (!foundTotal) {
                    foundTotal = /^TOTAL$/i.test(value);
                    continue;
                }
                if (/^(?:PRICE:|TOTAL:)$/i.test(value)) break;
                if (/^\d+$/.test(value)) values.push(value);
            }
            return values;
        };
        const parseQty = (text, comboText, comboTextParts, totalTextParts) => {
            const normalized = norm(text);
            const structuralQty = [...totalTextParts, ...comboTextParts]
                .map((part) => Number.parseInt(part, 10))
                .find((qty) => Number.isFinite(qty) && qty > 0);
            if (structuralQty !== undefined) {
                return structuralQty;
            }

            const comboQty = Number.parseInt(norm(comboText), 10);
            if (Number.isFinite(comboQty) && comboQty > 0) {
                return comboQty;
            }

            const totalMatch = normalized.match(/TOTAL0*(\d+)/i);
            const totalQty = totalMatch ? Number.parseInt(totalMatch[1], 10) : Number.NaN;
            if (Number.isFinite(totalQty)) {
                return totalQty;
            }

            return Number.isFinite(comboQty) ? comboQty : 0;
        };

        const skuPatternFor = (sku) => new RegExp(`SKU\\s*:\\s*${sku}(?:\\D|$)`, 'i');

        /** @type {Record<string, number>} */
        const map = {};
        for (const anchor of document.querySelectorAll('a[href*="/retailer/products/"]')) {
            const sku = skuFromHref(anchor.getAttribute('href') || '');
            if (!sku || Object.prototype.hasOwnProperty.call(map, sku)) {
                continue;
            }

            let container = anchor;
            /** @type {{ qty: number, textLen: number } | null} */
            let best = null;
            for (let depth = 0; depth < 14; depth += 1) {
                if (!container.parentElement) {
                    break;
                }
                container = container.parentElement;
                const text = norm(container.textContent || '');
                const skuPattern = skuPatternFor(sku);
                if (!skuPattern.test(text)) {
                    continue;
                }

                const combo = container.querySelector('button[role="combobox"]');
                const comboText = combo?.textContent || '';
                if (!comboText && !/TOTAL0*\d+/i.test(text)) {
                    continue;
                }

                const qty = parseQty(
                    text,
                    comboText,
                    numericTextParts(combo),
                    numericTextPartsAfterTotal(container),
                );
                if (!best || text.length < best.textLen) {
                    best = { qty, textLen: text.length };
                }
            }

            if (best) {
                map[sku] = best.qty;
            }
        }

        return map;
    });
}

async function scrapeRetailerCartPreorderArrivedQuantities(page) {
    return page.evaluate(() => {
        const norm = (value) =>
            String(value || '')
                .replace(/\s+/g, ' ')
                .trim();
        const skuFromHref = (href) => {
            const clean = String(href || '').split('?')[0];
            const parts = clean.split('/').filter(Boolean);
            const sku = parts[parts.length - 1] || '';
            return /^[0-9A-Za-z_-]+$/.test(sku) ? sku : '';
        };
        const readQty = (element) => {
            const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
            let insideArrivedPreorder = false;
            const candidates = [];
            while (walker.nextNode()) {
                const part = norm(walker.currentNode.textContent || '');
                if (!insideArrivedPreorder) {
                    insideArrivedPreorder = /PREORDER ARRIVED/i.test(part);
                    continue;
                }
                if (/^ORDERED$/i.test(part)) {
                    const qty = candidates.at(-1);
                    return Number.isFinite(qty) ? qty : 0;
                }
                if (/^\d+$/.test(part)) {
                    candidates.push(Number.parseInt(part, 10));
                }
            }
            return 0;
        };

        const map = {};
        for (const anchor of document.querySelectorAll('a[href*="/retailer/products/"]')) {
            const sku = skuFromHref(anchor.getAttribute('href') || '');
            if (!sku) {
                continue;
            }

            let container = anchor;
            let best = null;
            for (let depth = 0; depth < 14 && container.parentElement; depth += 1) {
                container = container.parentElement;
                const text = norm(container.textContent || '');
                if (!/PREORDER ARRIVED/i.test(text) || !/ORDERED/i.test(text)) {
                    continue;
                }
                const containerSkus = new Set(
                    [...container.querySelectorAll('a[href*="/retailer/products/"]')]
                        .map((link) => skuFromHref(link.getAttribute('href') || ''))
                        .filter((candidateSku) => candidateSku !== ''),
                );
                if (containerSkus.size !== 1 || !containerSkus.has(sku)) {
                    continue;
                }
                const qty = readQty(container);
                if (!best || text.length < best.textLen) {
                    best = { qty, textLen: text.length };
                }
            }

            if (best && best.qty > 0) {
                map[sku] = best.qty;
            }
        }

        return map;
    });
}

function isCartMutationContainer(text, hasCombobox, hasMinus, hasPlus) {
    return Boolean(hasCombobox || (/IN-STOCK/i.test(String(text || '')) && hasMinus && hasPlus));
}

async function markCartRow(page, sku) {
    return page.evaluate((targetSku) => {
        for (const element of document.querySelectorAll('[data-ovs-cart-row]')) {
            element.removeAttribute('data-ovs-cart-row');
        }
        const norm = (value) =>
            String(value || '')
                .replace(/\s+/g, ' ')
                .trim();
        const anchor = [...document.querySelectorAll('a[href*="/retailer/products/"]')].find(
            (element) => {
                const clean = String(element.getAttribute('href') || '').split('?')[0];
                return clean.endsWith(`/retailer/products/${targetSku}`);
            },
        );
        if (!anchor) {
            return false;
        }

        let container = anchor;
        let best = null;
        const skuPattern = new RegExp(`SKU\\s*:\\s*${targetSku}(?:\\D|$)`, 'i');
        for (let depth = 0; depth < 14 && container.parentElement; depth += 1) {
            container = container.parentElement;
            const text = norm(container.textContent);
            const hasCombobox = Boolean(container.querySelector('button[role="combobox"]'));
            const hasMinus = Boolean(container.querySelector('svg.lucide-minus'));
            const hasPlus = Boolean(container.querySelector('svg.lucide-plus'));
            const hasMutationControls =
                hasCombobox || (/IN-STOCK/i.test(text) && hasMinus && hasPlus);
            if (skuPattern.test(text) && hasMutationControls) {
                if (!best || text.length < norm(best.textContent).length) {
                    best = container;
                }
            }
        }
        if (!best) {
            return false;
        }
        best.setAttribute('data-ovs-cart-row', targetSku);
        return true;
    }, sku);
}

async function setCartRowQuantity(page, sku, targetQty) {
    if (!(await markCartRow(page, sku))) {
        throw new Error(`PLAMOD cart row was not found for SKU ${sku}.`);
    }
    let current = Number((await scrapeRetailerCartQuantities(page))[sku] || 0);
    for (let attempt = 0; attempt < 20 && current !== targetQty; attempt += 1) {
        const plan = cartLineStepperPlan(current, targetQty);
        if (!(await markCartRow(page, sku))) {
            throw new Error(`PLAMOD cart row disappeared for SKU ${sku}.`);
        }
        const row = page.locator(`[data-ovs-cart-row="${sku}"]`);
        const control = row.locator(cartLineStepperSelector(plan.direction)).first();
        if ((await control.count()) === 0 || !(await control.isVisible())) {
            throw new Error(`PLAMOD cart ${plan.direction} control was not found for SKU ${sku}.`);
        }
        const mutationResponsePromise = page
            .waitForResponse(
                (response) =>
                    response.url().includes('/retailer/cart') &&
                    response.request().method() === 'POST',
                { timeout: 15000 },
            )
            .catch(() => null);
        await control.click();
        const mutationResponse = await mutationResponsePromise;
        if (mutationResponse === null) {
            throw new Error(`PLAMOD did not confirm the cart quantity update for SKU ${sku}.`);
        }
        const mutationBody = await mutationResponse.text().catch(() => '');
        const responseError = mutationBody.match(/"code":"ERROR","message":"([^"]+)"/i);
        if (!mutationResponse.ok() || responseError) {
            throw new Error(
                responseError?.[1] ||
                    `PLAMOD rejected the cart quantity update for SKU ${sku} (${mutationResponse.status()}).`,
            );
        }
        await page.waitForTimeout(750);
        current = Number((await scrapeRetailerCartQuantities(page))[sku] || 0);
    }

    if (current !== targetQty) {
        throw new Error(
            `PLAMOD cart stayed at qty ${current} instead of ${targetQty} for SKU ${sku}.`,
        );
    }
}

async function findCartRowByScrolling(page, sku, positionHint = null, maxRounds = 60) {
    if (await markCartRow(page, sku)) {
        return true;
    }

    if (positionHint && Number.isFinite(positionHint.ratio)) {
        const baseRatio = positionHint.ratio;
        for (const offset of [0, -0.03, 0.03, -0.06, 0.06, -0.1, 0.1]) {
            await positionRetailerCartScroll(page, baseRatio + offset);
            await page.waitForTimeout(500);
            if (await markCartRow(page, sku)) {
                return true;
            }
        }
        await resetRetailerCartScroll(page);
        await page.waitForTimeout(500);
    }

    // The collector's discovery order is not guaranteed to match the retailer's
    // visual order, so use a deterministic full-height sweep as the fallback.
    // The 2.5% interval is smaller than one rendered viewport in the 132-line
    // cart and therefore cannot skip a virtualized row.
    for (let step = 0; step <= 40; step += 1) {
        await positionRetailerCartScroll(page, step / 40);
        await page.waitForTimeout(250);
        if (await markCartRow(page, sku)) {
            return true;
        }
    }

    await resetRetailerCartScroll(page);
    await page.waitForTimeout(500);
    let staleRounds = 0;
    let lastSignature = '';
    for (let round = 0; round < maxRounds; round += 1) {
        // Use a smaller step than the full-cart collector so a virtualized row
        // cannot fall entirely between two rendered viewport windows.
        await scrollRetailerCartStep(page, 1000);
        await page.waitForTimeout(250);
        if (await markCartRow(page, sku)) {
            return true;
        }

        const signature = Object.keys(await scrapeRetailerCartQuantities(page)).join('|');
        if (signature === lastSignature) {
            staleRounds += 1;
            if (staleRounds >= 8) {
                break;
            }
        } else {
            staleRounds = 0;
            lastSignature = signature;
        }
    }

    return false;
}

async function snapshotRetailerCart(page, options = {}) {
    const refresh = options.refresh !== false;
    const expectedSkus = Array.isArray(options.expectedSkus) ? options.expectedSkus : [];
    const expectedQuantities =
        options.expectedQuantities && typeof options.expectedQuantities === 'object'
            ? options.expectedQuantities
            : {};
    const maxAttempts = Number.isFinite(options.maxAttempts) ? options.maxAttempts : 4;

    await page
        .waitForSelector('a[href*="/retailer/products/"], [data-testid="empty-cart"]', {
            state: 'attached',
            timeout: 5000,
        })
        .catch(() => undefined);

    if (refresh) {
        const refreshButton = page.getByRole('button', { name: /refresh/i });
        if ((await refreshButton.count()) > 0) {
            await refreshButton
                .first()
                .click()
                .catch(() => undefined);
            if (CART_SETTLE_MS > 0) {
                await page.waitForTimeout(CART_SETTLE_MS);
            }
        }
    }

    const badgeCount = await readRetailerCartItemBadgeCount(page);
    const scrollTarget = Math.max(
        badgeCount,
        expectedSkus.length,
        ...(expectedSkus.length > 0
            ? expectedSkus.map((sku) => Math.max(0, Number(expectedQuantities[sku] ?? 0)))
            : [0]),
    );

    let latest = await collectRetailerCartQuantities(page, {
        targetCount: scrollTarget > 0 ? scrollTarget : 0,
        preorderArrived: options.preorderArrived,
    });

    for (let attempt = 1; attempt < maxAttempts; attempt += 1) {
        if (expectedSkus.length === 0) {
            break;
        }

        const pending = expectedSkus.filter((sku) => {
            const expected = Math.max(0, Number(expectedQuantities[sku] ?? 0));
            return Number(latest[sku] ?? 0) < expected;
        });
        if (pending.length === 0) {
            break;
        }

        await page.waitForTimeout(350);
        // Re-read visible rows only — do not re-scroll from the top or we lose the
        // merged virtualized cart snapshot collected on the first pass.
        latest = {
            ...latest,
            ...(await scrapeRetailerCartQuantities(page)),
        };
        if (options.preorderArrived) {
            Object.assign(
                options.preorderArrived,
                await scrapeRetailerCartPreorderArrivedQuantities(page),
            );
        }
    }

    return latest;
}

async function selectInStockQty(page, targetQty, currentCartQty = 0) {
    await waitForInStockBlock(page);
    const block = getInStockBlock(page);
    const initialState = await readInStockBlockState(block);
    const moq =
        initialState.moqQty > 0
            ? initialState.moqQty
            : parseMoqFromBlockText(initialState.text, initialState.comboQty);

    if (targetQty < moq) {
        return {
            selected_qty: 0,
            max_available: null,
            moq,
            error_message: `Requested ${targetQty} but PLAMOD MOQ is ${moq}.`,
        };
    }

    await setCartQuantityViaPackStepper(page, block, targetQty, currentCartQty);

    return {
        selected_qty: targetQty,
        max_available: null,
        moq,
        error_message: null,
    };
}

function buildVerificationStatus(requestedQty, beforeQty, afterQty, addFailed) {
    if (addFailed) {
        return 'add_failed';
    }
    if (afterQty > requestedQty) {
        return 'over_added';
    }
    if (beforeQty === requestedQty && afterQty === requestedQty) {
        return 'already_satisfied';
    }
    if (afterQty === requestedQty) {
        return 'verified';
    }
    if (afterQty > 0) {
        return 'partial';
    }
    return 'missing';
}

function buildExtraCartLines(lineResults, cartAfter) {
    const requestedSkus = new Set(
        lineResults.map((line) => normalizeSku(String(line.sku || ''))).filter((sku) => sku !== ''),
    );
    /** @type {Array<{ sku: string, cart_qty: number }>} */
    const extraCartLines = [];
    for (const [sku, qtyRaw] of Object.entries(cartAfter || {})) {
        const normalizedSku = normalizeSku(sku);
        const cartQty = Number.parseInt(String(qtyRaw ?? '0'), 10) || 0;
        if (normalizedSku === '' || cartQty <= 0 || requestedSkus.has(normalizedSku)) {
            continue;
        }
        extraCartLines.push({ sku: normalizedSku, cart_qty: cartQty });
    }
    extraCartLines.sort((left, right) => left.sku.localeCompare(right.sku));
    return extraCartLines;
}

function summarizeReport(lines) {
    const summary = {
        requested_lines: lines.length,
        verified: 0,
        partial: 0,
        over_added: 0,
        missing: 0,
        add_failed: 0,
        already_satisfied: 0,
        all_verified: false,
        extra_cart_lines: 0,
        order_matches_cart: false,
    };
    for (const line of lines) {
        if (line.verification_status === 'verified') {
            summary.verified += 1;
        } else if (line.verification_status === 'partial') {
            summary.partial += 1;
        } else if (line.verification_status === 'over_added') {
            summary.over_added += 1;
        } else if (line.verification_status === 'missing') {
            summary.missing += 1;
        } else if (line.verification_status === 'add_failed') {
            summary.add_failed += 1;
        } else if (line.verification_status === 'already_satisfied') {
            summary.already_satisfied += 1;
        }
    }
    summary.all_verified =
        summary.requested_lines > 0 &&
        summary.verified + summary.already_satisfied === summary.requested_lines &&
        summary.partial === 0 &&
        summary.over_added === 0 &&
        summary.missing === 0 &&
        summary.add_failed === 0;
    return summary;
}

function isCartSnapshotCredible(cart, explicitEmptyCart) {
    return Object.keys(cart).length > 0 || explicitEmptyCart;
}

async function hasExplicitEmptyCartState(page) {
    return page.evaluate(() => {
        if (document.querySelector('[data-testid="empty-cart"]')) {
            return true;
        }
        const text = String(document.body?.innerText || '')
            .replace(/\s+/g, ' ')
            .trim();
        return /(?:cart is empty|empty cart|no items in (?:your|the) cart|your cart has no items)/i.test(
            text,
        );
    });
}

/**
 * @param {string} baseUrl
 * @param {Array<Record<string, unknown>>} lineResults
 * @param {Record<string, number>} cartBefore
 * @param {Record<string, number>} cartAfter
 * @param {{ rechecked_at?: string|null, preorder_arrived?: Record<string, number> }} [extras]
 */
function buildVerificationReport(baseUrl, lineResults, cartBefore, cartAfter, extras = {}) {
    const preorderArrived =
        extras.preorder_arrived && typeof extras.preorder_arrived === 'object'
            ? extras.preorder_arrived
            : {};
    const verifiedLines = lineResults.map((line) => {
        const sku = String(line.sku || '');
        const requestedQty = Number(line.requested_qty || 0);
        const preorderArrivedQty = Number(preorderArrived[sku] || 0);
        const targetInStockQty = cartLineTargetInStockQty(
            line.source,
            requestedQty,
            preorderArrivedQty,
        );
        const beforeQty = Number(cartBefore[sku] || 0);
        const afterQty = Number(cartAfter[sku] || 0);
        const addFailed = line.add_status === 'failed';
        const verificationStatus = buildVerificationStatus(
            targetInStockQty,
            beforeQty,
            afterQty,
            addFailed,
        );
        return {
            ...line,
            error_message:
                verificationStatus === 'verified' || verificationStatus === 'already_satisfied'
                    ? null
                    : (line.error_message ?? null),
            cart_qty_before: beforeQty,
            cart_qty_after: afterQty,
            cart_qty_added: Math.max(0, afterQty - beforeQty),
            preorder_arrived_qty: preorderArrivedQty,
            target_instock_qty: targetInStockQty,
            verification_status: verificationStatus,
        };
    });

    const summary = summarizeReport(verifiedLines);
    const extraCartLines = buildExtraCartLines(lineResults, cartAfter);
    summary.extra_cart_lines = extraCartLines.length;
    summary.order_matches_cart = summary.all_verified && extraCartLines.length === 0;
    /** @type {Record<string, unknown>} */
    const report = {
        cart_url: `${baseUrl}/retailer/cart`,
        cart_before: { ...cartBefore },
        cart_after: { ...cartAfter },
        preorder_arrived: { ...preorderArrived },
        summary,
        lines: verifiedLines,
        extra_cart_lines: extraCartLines,
    };
    if (extras.rechecked_at) {
        report.rechecked_at = extras.rechecked_at;
    }
    if (extras.verified_at) {
        report.verified_at = extras.verified_at;
    }
    if (extras.scope) {
        report.scope = extras.scope;
    }
    if (Number.isFinite(extras.cart_item_badge_count)) {
        report.cart_item_badge_count = extras.cart_item_badge_count;
    }
    if (Number.isFinite(extras.cart_lines_detected)) {
        report.cart_lines_detected = extras.cart_lines_detected;
    }

    return report;
}

/**
 * @param {object} deps
 * @param {(page: import('playwright').Page, baseUrl: string, context: import('playwright').BrowserContext) => Promise<void>} deps.ensureLoggedInQuick
 * @param {(page: import('playwright').Page, baseUrl: string, sku: string, context: import('playwright').BrowserContext) => Promise<void>} deps.ensureOnRetailerPdp
 */
async function restockAddLinesToCartUnlocked(deps, payload) {
    const started = Date.now();
    const phaseTimings = {};
    const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
    const items = Array.isArray(payload?.items) ? payload.items : [];
    const normalizedItems = items
        .map((item) => ({
            sku: normalizeSku(item?.sku),
            qty: Number.parseInt(String(item?.qty ?? '0'), 10),
            product_name: String(item?.product_name || '').trim(),
            source: String(item?.source || '').trim(),
        }))
        .filter((item) => item.sku !== '' && isRestockCartRequestedQty(item.qty));

    if (normalizedItems.length === 0) {
        return {
            ok: false,
            error_message: 'No cart lines provided.',
            duration_ms: Date.now() - started,
        };
    }

    clearRestockCartProgress();
    writeRestockCartProgress({
        active: true,
        phase: 'starting',
        items_total: normalizedItems.length,
        items_processed: 0,
        lines: [],
    });

    /** @type {Array<Record<string, unknown>>} */
    const lineResults = [];
    /** @type {import('playwright').BrowserContext | null} */
    let context = null;

    try {
        const sessionStarted = Date.now();
        const session = await acquireCartSession(deps, baseUrl);
        context = session.context;
        let { page } = session;
        // Read-only retailer pages can remain visible after PLAMOD's mutation
        // session expires. Re-authenticate cart-write runs so a 200 response cannot
        // contain "You must be logged in" while every requested change is discarded.
        await context.clearCookies();
        await page.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
        await page
            .evaluate(() => {
                window.localStorage.clear();
                window.sessionStorage.clear();
            })
            .catch(() => undefined);
        await deps.ensureLoggedInQuick(page, baseUrl, context);
        phaseTimings.session_ms = Date.now() - sessionStarted;

        writeRestockCartProgress({ phase: 'snapshot_before' });
        const baselineStarted = Date.now();
        await page.goto(`${baseUrl}/retailer/cart`, {
            waitUntil: 'domcontentloaded',
            timeout: 20000,
        });
        const preorderArrivedBefore = {};
        let cartBeforeSnapshot = await snapshotRetailerCart(page, {
            refresh: false,
            maxAttempts: 2,
            preorderArrived: preorderArrivedBefore,
        });
        if (Object.keys(cartBeforeSnapshot).length === 0) {
            await page.close().catch(() => undefined);
            page = await context.newPage();
            await page.goto(`${baseUrl}/retailer/cart`, {
                waitUntil: 'domcontentloaded',
                timeout: 20000,
            });
            cartBeforeSnapshot = await snapshotRetailerCart(page, {
                refresh: false,
                maxAttempts: 2,
                preorderArrived: preorderArrivedBefore,
            });
        }
        const cartBefore = Object.freeze({ ...cartBeforeSnapshot });
        const baselinePage = page;
        page = await context.newPage();
        await baselinePage.close().catch(() => undefined);
        phaseTimings.baseline_ms = Date.now() - baselineStarted;

        writeRestockCartProgress({ phase: 'adding', cart_before: cartBefore });

        const addingStarted = Date.now();
        for (let index = 0; index < normalizedItems.length; index += 1) {
            const item = normalizedItems[index];
            writeRestockCartProgress({
                phase: 'adding',
                items_processed: index,
                current_sku: item.sku,
            });

            /** @type {Record<string, unknown>} */
            const result = {
                sku: item.sku,
                product_name: item.product_name,
                source: item.source,
                requested_qty: item.qty,
                selected_qty: 0,
                max_available: null,
                preorder_arrived_qty: Number(preorderArrivedBefore[item.sku] || 0),
                target_instock_qty: 0,
                add_status: 'pending',
                error_message: null,
            };

            try {
                const cartQtyBeforeLine = Number(cartBefore[item.sku] || 0);
                const targetInStockQty = cartLineTargetInStockQty(
                    item.source,
                    item.qty,
                    result.preorder_arrived_qty,
                );
                result.target_instock_qty = targetInStockQty;
                const action = cartLineAdjustmentAction(cartQtyBeforeLine, targetInStockQty);
                if (action === 'exact') {
                    result.selected_qty = targetInStockQty;
                    result.add_status = 'already_satisfied';
                    lineResults.push(result);
                    writeRestockCartProgress({
                        phase: 'adding',
                        items_processed: index + 1,
                        current_sku: item.sku,
                        lines: lineResults,
                    });
                    continue;
                }

                const mutationSurface = cartLineMutationSurface(action);
                if (mutationSurface === 'pdp') {
                    await deps.ensureOnRetailerPdp(page, baseUrl, item.sku, context);
                    const selection = await selectInStockQty(
                        page,
                        targetInStockQty,
                        cartQtyBeforeLine,
                    );
                    if (selection.error_message) {
                        throw new Error(selection.error_message);
                    }
                    result.max_available = selection.max_available;
                    result.add_status = 'created';
                    result.selected_qty = selection.selected_qty;
                }
                if (mutationSurface === 'cart') {
                    await page.goto(`${baseUrl}/retailer/cart`, {
                        waitUntil: 'domcontentloaded',
                        timeout: 20000,
                    });
                    let foundCartRow = await findCartRowByScrolling(page, item.sku);
                    if (!foundCartRow) {
                        const stalePage = page;
                        page = await context.newPage();
                        await page.goto(`${baseUrl}/retailer/cart`, {
                            waitUntil: 'domcontentloaded',
                            timeout: 20000,
                        });
                        await stalePage.close().catch(() => undefined);
                        foundCartRow = await findCartRowByScrolling(page, item.sku);
                    }
                    if (!foundCartRow) {
                        const replacement = await replaceCartWriteSession(deps, baseUrl, context);
                        context = replacement.context;
                        page = replacement.page;
                        await page.goto(`${baseUrl}/retailer/cart`, {
                            waitUntil: 'domcontentloaded',
                            timeout: 20000,
                        });
                        foundCartRow = await findCartRowByScrolling(page, item.sku);
                    }
                    if (!foundCartRow) {
                        throw new Error(`PLAMOD cart row was not found for SKU ${item.sku}.`);
                    }
                    await setCartRowQuantity(page, item.sku, targetInStockQty);
                    result.add_status = 'updated';
                    result.selected_qty = targetInStockQty;
                }
            } catch (e) {
                result.add_status = 'failed';
                result.error_message = String(e?.message || 'Add to cart failed');
            }

            lineResults.push(result);
            writeRestockCartProgress({
                phase: 'adding',
                items_processed: index + 1,
                current_sku: item.sku,
                lines: lineResults,
            });
        }
        phaseTimings.update_lines_ms = Date.now() - addingStarted;

        writeRestockCartProgress({ phase: 'setting_quantities' });
        phaseTimings.set_quantities_ms = 0;

        writeRestockCartProgress({ phase: 'verifying_cart', lines: lineResults });
        const verifyStarted = Date.now();
        await context.close().catch(() => undefined);
        const verificationSession = await acquireCartSession(deps, baseUrl);
        context = verificationSession.context;
        page = verificationSession.page;
        await page
            .goto(`${baseUrl}/retailer/cart`, { waitUntil: 'domcontentloaded', timeout: 20000 })
            .catch(async () => {
                await page.waitForTimeout(1500);
                await page.goto(`${baseUrl}/retailer/cart`, {
                    waitUntil: 'domcontentloaded',
                    timeout: 20000,
                });
            });
        await page.waitForTimeout(2000);
        const expectedSkus = normalizedItems.map((item) => item.sku);
        const expectedQuantities = Object.fromEntries(
            lineResults.map((line) => [line.sku, line.target_instock_qty]),
        );
        const preorderArrivedAfter = {};
        const cartAfter = await snapshotRetailerCart(page, {
            refresh: false,
            expectedSkus,
            expectedQuantities,
            maxAttempts: 12,
            preorderArrived: preorderArrivedAfter,
        });
        phaseTimings.verify_ms = Date.now() - verifyStarted;

        const report = buildVerificationReport(baseUrl, lineResults, cartBefore, cartAfter, {
            preorder_arrived: preorderArrivedAfter,
        });
        report.phase_timings_ms = phaseTimings;

        writeRestockCartProgress({
            active: false,
            phase: 'completed',
            report,
            items_processed: normalizedItems.length,
        });

        return {
            ok: true,
            duration_ms: Date.now() - started,
            report,
            phase_timings_ms: phaseTimings,
        };
    } catch (e) {
        writeRestockCartProgress({
            active: false,
            phase: 'failed',
            error_message: String(e?.message || 'Cart automation failed'),
        });
        return {
            ok: false,
            error_message: String(e?.message || 'Cart automation failed'),
            duration_ms: Date.now() - started,
            lines: lineResults,
        };
    } finally {
        await context?.close().catch(() => undefined);
    }
}

async function restockAddLinesToCart(deps, payload) {
    return withCartSessionMutex(() => restockAddLinesToCartUnlocked(deps, payload));
}

/**
 * Re-scrape PLAMOD cart and rebuild verification against a stored cart_before baseline.
 *
 * @param {object} deps
 * @param {(page: import('playwright').Page, baseUrl: string, context: import('playwright').BrowserContext) => Promise<void>} deps.ensureLoggedInQuick
 */
async function restockVerifyCartUnlocked(deps, payload) {
    const started = Date.now();
    const baseUrl = String(process.env.PLAMOD_BASE_URL || 'https://plamod.com').replace(/\/+$/, '');
    const cartBeforeRaw =
        payload?.cart_before && typeof payload.cart_before === 'object' ? payload.cart_before : {};
    /** @type {Record<string, number>} */
    const cartBefore = {};
    for (const [sku, qty] of Object.entries(cartBeforeRaw)) {
        const normalized = normalizeSku(sku);
        if (normalized !== '') {
            cartBefore[normalized] = Number.parseInt(String(qty ?? '0'), 10) || 0;
        }
    }

    const linesIn = Array.isArray(payload?.lines) ? payload.lines : [];
    const lineResults = linesIn
        .map((line) => ({
            sku: normalizeSku(line?.sku),
            product_name: String(line?.product_name || '').trim(),
            source: String(line?.source || '').trim(),
            requested_qty: Number.parseInt(String(line?.requested_qty ?? '0'), 10),
            selected_qty: line?.selected_qty ?? null,
            max_available: line?.max_available ?? null,
            add_status: 'rechecked',
            error_message: line?.error_message ? String(line.error_message) : null,
        }))
        .filter((line) => line.sku !== '' && isRestockCartRequestedQty(line.requested_qty));

    if (lineResults.length === 0) {
        return {
            ok: false,
            error_message: 'No cart report lines provided.',
            duration_ms: Date.now() - started,
        };
    }

    /** @type {import('playwright').BrowserContext | null} */
    let context = null;
    try {
        const session = await acquireCartSession(deps, baseUrl);
        context = session.context;
        let page = session.page;
        const expectedSkus = lineResults
            .map((line) => String(line.sku || ''))
            .filter((sku) => sku !== '');
        const expectedQuantities = Object.fromEntries(
            lineResults.map((line) => [String(line.sku || ''), Number(line.requested_qty || 0)]),
        );
        let cartAfter = null;
        let preorderArrived = {};
        for (let attempt = 0; attempt < 3; attempt += 1) {
            if (attempt > 0) {
                await page.close().catch(() => undefined);
                page = await context.newPage();
            }
            if (page.url().includes('/retailer/cart')) {
                await page.reload({ waitUntil: 'domcontentloaded', timeout: 20000 });
            } else {
                await page.goto(`${baseUrl}/retailer/cart`, {
                    waitUntil: 'domcontentloaded',
                    timeout: 20000,
                });
            }
            await page.waitForTimeout(2000);
            const candidatePreorderArrived = {};
            const candidate = await snapshotRetailerCart(page, {
                refresh: true,
                expectedSkus,
                expectedQuantities,
                maxAttempts: 4,
                preorderArrived: candidatePreorderArrived,
            });
            if (isCartSnapshotCredible(candidate, await hasExplicitEmptyCartState(page))) {
                cartAfter = candidate;
                preorderArrived = candidatePreorderArrived;
                break;
            }
        }
        if (cartAfter === null) {
            throw new Error(
                'PLAMOD cart verification was inconclusive because the refreshed cart did not render.',
            );
        }
        const report = buildVerificationReport(baseUrl, lineResults, cartBefore, cartAfter, {
            rechecked_at: new Date().toISOString(),
            scope: String(payload?.scope || '').trim() || undefined,
            verified_at:
                String(payload?.scope || '').trim() === 'full_order'
                    ? new Date().toISOString()
                    : undefined,
            cart_item_badge_count: await readRetailerCartItemBadgeCount(page),
            cart_lines_detected: Object.keys(cartAfter).length,
            preorder_arrived: preorderArrived,
        });

        return {
            ok: true,
            duration_ms: Date.now() - started,
            report,
        };
    } catch (e) {
        return {
            ok: false,
            error_message: String(e?.message || 'Cart verification failed'),
            duration_ms: Date.now() - started,
        };
    } finally {
        await context?.close().catch(() => undefined);
    }
}

async function restockVerifyCart(deps, payload) {
    return withCartSessionMutex(() => restockVerifyCartUnlocked(deps, payload));
}

module.exports = {
    restockCartProgressPath,
    writeRestockCartProgress,
    readRestockCartProgress,
    clearRestockCartProgress,
    scrapeRetailerCartQuantities,
    scrapeRetailerCartPreorderArrivedQuantities,
    collectRetailerCartQuantities,
    parseRetailerCartItemBadgeCount,
    readRetailerCartItemBadgeCount,
    snapshotRetailerCart,
    parseCartRowQty,
    parsePreorderArrivedQty,
    isPreorderArrivedContainerText,
    isSkuScopedCartContainer,
    parseMoqFromBlockText,
    markInStockBlock,
    getInStockBlock,
    readInStockBlockState,
    selectInStockQty,
    cartLineAdjustmentAction,
    cartLineTargetInStockQty,
    isRestockCartRequestedQty,
    cartLineMutationSurface,
    cartLineStepperPlan,
    cartLineStepperSelector,
    isCartMutationContainer,
    buildVerificationStatus,
    buildExtraCartLines,
    buildVerificationReport,
    summarizeReport,
    isCartSnapshotCredible,
    restockAddLinesToCart,
    restockVerifyCart,
};
