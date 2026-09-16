import { expect, test } from './fixtures';

const ORDER_ID = '00000000-0000-4000-8000-00000000e2e1';

const QUOTE_ORDER_ID = '00000000-0000-4000-8000-00000000e2e2';
const SUGGEST_ORDER_ID = '00000000-0000-4000-8000-00000000e2e3';
const QUOTE_FX = 4.8404;

type ShippingPatch = {
    actual_shipping_cost_input_mode?: string | null;
    actual_shipping_weight_kg?: string | null;
    actual_shipping_cost_amount?: string | null;
};

type QuoteShippingPatch = {
    shipping_cost_input_mode?: string | null;
    shipping_weight_kg?: string | null;
    shipping_cost_amount?: string | null;
};

function quoteLandedCad(productRmb: number, shippingRmb: number): string {
    return ((productRmb + shippingRmb) / QUOTE_FX).toFixed(2);
}

function lockedOrder(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: ORDER_ID,
        customer_contact_media: 'ig',
        customer_contact_media_label: 'Instagram',
        customer_contact_value: 'E2E-shipping-weight',
        product_name: 'E2E shipping weight kit',
        vendor: null,
        customer_visual: null,
        product_visual: null,
        merchandiser_order_proof_visual: null,
        product_cost_amount: '275.00',
        product_cost_currency: 'CNY',
        product_cost_currency_label: 'RMB',
        shipping_cost_amount: '100.00',
        shipping_cost_currency: 'CNY',
        shipping_cost_input_mode: 'amount',
        shipping_weight_kg: null,
        shipping_cost_currency_label: 'RMB',
        landed_cost_cad: '77.37',
        product_fx_rate_to_cad: '4.847100',
        shipping_fx_rate_to_cad: '4.847100',
        fx_rate_date: '2026-09-11',
        receive_delay_amount: 6,
        receive_delay_unit: 'weeks',
        receive_delay_unit_label: 'Weeks',
        receive_delay_days: 42,
        receive_delay_label: '6 weeks',
        actual_product_cost_amount: '275.00',
        actual_product_cost_currency: 'CNY',
        actual_product_cost_currency_label: 'RMB',
        actual_shipping_cost_amount: '100.00',
        actual_shipping_cost_currency: 'CNY',
        actual_shipping_cost_input_mode: 'amount',
        actual_shipping_weight_kg: null,
        actual_shipping_cost_currency_label: 'RMB',
        actual_landed_cost_cad: '77.37',
        actual_product_fx_rate_to_cad: '4.847100',
        actual_shipping_fx_rate_to_cad: '4.847100',
        actual_fx_rate_date: '2026-09-11',
        actual_receive_delay_amount: null,
        actual_receive_delay_unit: null,
        actual_receive_delay_unit_label: null,
        actual_receive_delay_days: null,
        actual_receive_delay_label: null,
        actual_arrival_at: null,
        quote_status: 'quoted',
        merchandiser_price_multiplier: '1.10',
        merchandiser_price_cad: '85.11',
        formula_merchandiser_price_cad: '85.11',
        effective_merchandiser_multiplier: '1.10',
        merchandiser_commission_cad: '7.74',
        merchandiser_commission_override_cad: null,
        our_price_multiplier: '1.31',
        customer_price_cad: '109.99',
        formula_our_price_cad: '109.99',
        effective_our_multiplier: '1.31',
        our_commission_cad: '24.88',
        our_commission_override_cad: null,
        deposit_percent: '20.00',
        deposit_amount_cad: '22.00',
        deposit_amount_override_cad: null,
        balance_cad: '87.99',
        pricing_status: 'priced',
        offer_locked_at: '2026-09-10T00:04:00-04:00',
        customer_considering_at: null,
        deposit_received_at: null,
        balance_received_at: null,
        cash_received_cad: null,
        cash_received_at: null,
        shopify_invoices: { customer_gid: null, deposit: null, balance: null },
        merchandiser_ordered_at: null,
        estimated_arrival_at: null,
        product_received_at: null,
        rejected_at: null,
        competitor_prices_product_name: 'E2E shipping weight kit',
        competitor_price_quotes: [
            {
                site_key: 'gundam_hangar',
                site_name: 'Gundam Hangar',
                site_url: 'https://example.com',
                status: 'not_found',
                availability: null,
                currency: 'CAD',
                price: null,
                original_price: null,
                product_url: null,
                error_message: null,
            },
        ],
        competitor_prices_fetched_at: '2026-09-10T00:00:00-04:00',
        competitor_prices_refresh_status: 'completed',
        competitor_prices_refresh_scope: 'fast',
        competitor_prices_refresh_error: null,
        competitor_prices_target_sites: [],
        notes: null,
        created_at: '2026-09-10T00:00:00-04:00',
        updated_at: '2026-09-10T00:00:00-04:00',
        ...overrides,
    };
}

test('reconciliation weight mode stays selected after save settle', async ({ page }) => {
    test.setTimeout(90_000);

    const pageErrors: string[] = [];
    page.on('pageerror', (error) => {
        pageErrors.push(error.message);
    });

    const patches: ShippingPatch[] = [];

    await page.route('**/api/v1/special-orders/filter-options', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    contact_media: [
                        { value: 'ig', label: 'Instagram' },
                        { value: 'fb', label: 'Facebook' },
                    ],
                    currencies: [
                        { value: 'CAD', label: 'CAD' },
                        { value: 'CNY', label: 'RMB' },
                        { value: 'HKD', label: 'HKD' },
                        { value: 'JPY', label: 'JPY' },
                    ],
                    receive_delay_units: [
                        { value: 'days', label: 'Days' },
                        { value: 'weeks', label: 'Weeks' },
                        { value: 'months', label: 'Months' },
                    ],
                    quote_statuses: [],
                    pricing_statuses: [],
                    lifecycle_statuses: [],
                },
            }),
        });
    });

    await page.route('**/api/v1/maintenance/special-order-pricing-caps', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    merchandiser_commission_cap_cad: '50.00',
                    opv_margin_cap_cad: '150.00',
                    default_shipping_cost_per_kg_cny: '29.00',
                },
            }),
        });
    });

    await page.route(`**/api/v1/special-orders/${ORDER_ID}`, async (route) => {
        if (route.request().method() === 'GET') {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: lockedOrder() }),
            });
            return;
        }

        if (route.request().method() === 'PATCH') {
            const body = (route.request().postDataJSON() ?? {}) as ShippingPatch;
            patches.push({
                actual_shipping_cost_input_mode: body.actual_shipping_cost_input_mode ?? null,
                actual_shipping_weight_kg: body.actual_shipping_weight_kg ?? null,
                actual_shipping_cost_amount: body.actual_shipping_cost_amount ?? null,
            });
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: lockedOrder({
                        actual_shipping_cost_input_mode: 'amount',
                        actual_shipping_weight_kg: null,
                        actual_shipping_cost_amount: '100.00',
                    }),
                }),
            });
            return;
        }

        await route.fallback();
    });

    await page.goto(`/special-orders/${ORDER_ID}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Reconciliation' })).toBeVisible({
        timeout: 30_000,
    });

    const recon = page.locator('.cao-detail__reconciliation-compact');
    const weightRadio = recon.locator('input[name="cao-actual-shipping-mode"][value="weight"]');
    const amountRadio = recon.locator('input[name="cao-actual-shipping-mode"][value="amount"]');

    await expect(amountRadio).toBeChecked();
    await weightRadio.check();
    await expect(weightRadio).toBeChecked();
    await expect(recon.locator('input[step="0.001"]')).toBeVisible();

    await page.waitForTimeout(4000);
    await expect(weightRadio).toBeChecked();
    await expect(amountRadio).not.toBeChecked();
    await expect(recon.locator('input[step="0.001"]')).toBeVisible();

    expect(patches.length).toBeGreaterThan(0);
    expect(patches.every((patch) => patch.actual_shipping_cost_input_mode === 'weight')).toBe(true);
    expect(pageErrors, pageErrors.join('\n')).toEqual([]);

    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });

    const kgInput = recon.locator('input[step="0.001"]');
    await kgInput.fill('2');
    await kgInput.press('Tab');
    await page.waitForTimeout(4000);

    expect(pageErrors.concat(consoleErrors), [...pageErrors, ...consoleErrors].join('\n')).toEqual(
        [],
    );
    await expect(page.getByRole('heading', { name: 'Reconciliation' })).toBeVisible();
    await expect(weightRadio).toBeChecked();
    await expect(amountRadio).not.toBeChecked();
    await expect(kgInput).toHaveValue('2');
    await expect(recon.getByText('= 58.00 RMB')).toBeVisible();

    await recon.scrollIntoViewIfNeeded();
    await page.setViewportSize({ width: 1400, height: 900 });
    await recon.screenshot({
        path: 'test-results/recon-shipping-weight-desktop-1400.png',
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await recon.scrollIntoViewIfNeeded();
    await recon.screenshot({
        path: 'test-results/recon-shipping-weight-mobile-390.png',
    });
});

test('quote weight mode stays selected and computes kg × 29 after typing weight', async ({
    page,
}) => {
    test.setTimeout(90_000);

    const pageErrors: string[] = [];
    page.on('pageerror', (error) => {
        pageErrors.push(error.message);
    });

    const patches: QuoteShippingPatch[] = [];
    const productRmb = 180;
    const amountShippingRmb = 75;
    const weightKg = 2;
    const weightShippingRmb = weightKg * 29;
    const amountLanded = quoteLandedCad(productRmb, amountShippingRmb);
    const weightLanded = quoteLandedCad(productRmb, weightShippingRmb);

    await page.route('**/api/v1/special-orders/filter-options', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    contact_media: [
                        { value: 'ig', label: 'Instagram' },
                        { value: 'fb', label: 'Facebook' },
                    ],
                    currencies: [
                        { value: 'CAD', label: 'CAD' },
                        { value: 'CNY', label: 'RMB' },
                        { value: 'HKD', label: 'HKD' },
                        { value: 'JPY', label: 'JPY' },
                    ],
                    receive_delay_units: [
                        { value: 'days', label: 'Days' },
                        { value: 'weeks', label: 'Weeks' },
                        { value: 'months', label: 'Months' },
                    ],
                    quote_statuses: [],
                    pricing_statuses: [],
                    lifecycle_statuses: [],
                },
            }),
        });
    });

    await page.route('**/api/v1/maintenance/special-order-pricing-caps', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    merchandiser_commission_cap_cad: '50.00',
                    opv_margin_cap_cad: '150.00',
                    default_shipping_cost_per_kg_cny: '29.00',
                },
            }),
        });
    });

    await page.route(`**/api/v1/special-orders/${QUOTE_ORDER_ID}`, async (route) => {
        if (route.request().method() === 'GET') {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: lockedOrder({
                        id: QUOTE_ORDER_ID,
                        customer_contact_value: 'E2E-quote-shipping-weight',
                        product_name: 'Nether Emperor Full Armor',
                        product_cost_amount: '180.00',
                        shipping_cost_amount: '75.00',
                        shipping_cost_input_mode: 'amount',
                        shipping_weight_kg: null,
                        landed_cost_cad: amountLanded,
                        product_fx_rate_to_cad: String(QUOTE_FX),
                        shipping_fx_rate_to_cad: String(QUOTE_FX),
                        fx_rate_date: '2026-09-12',
                        offer_locked_at: null,
                        actual_product_cost_amount: null,
                        actual_shipping_cost_amount: null,
                        actual_shipping_cost_input_mode: 'amount',
                        actual_landed_cost_cad: null,
                    }),
                }),
            });
            return;
        }

        if (route.request().method() === 'PATCH') {
            const body = (route.request().postDataJSON() ?? {}) as QuoteShippingPatch;
            patches.push({
                shipping_cost_input_mode: body.shipping_cost_input_mode ?? null,
                shipping_weight_kg: body.shipping_weight_kg ?? null,
                shipping_cost_amount: body.shipping_cost_amount ?? null,
            });
            const kg = Number(body.shipping_weight_kg ?? '');
            const shippingRmb =
                body.shipping_cost_input_mode === 'weight' && Number.isFinite(kg) && kg > 0
                    ? kg * 29
                    : Number(body.shipping_cost_amount ?? amountShippingRmb);
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: lockedOrder({
                        id: QUOTE_ORDER_ID,
                        customer_contact_value: 'E2E-quote-shipping-weight',
                        product_name: 'Nether Emperor Full Armor',
                        product_cost_amount: '180.00',
                        shipping_cost_amount: shippingRmb.toFixed(2),
                        shipping_cost_input_mode: 'amount',
                        shipping_weight_kg: null,
                        landed_cost_cad: quoteLandedCad(productRmb, shippingRmb),
                        product_fx_rate_to_cad: String(QUOTE_FX),
                        shipping_fx_rate_to_cad: String(QUOTE_FX),
                        fx_rate_date: '2026-09-12',
                        offer_locked_at: null,
                        actual_product_cost_amount: null,
                        actual_shipping_cost_amount: null,
                        actual_shipping_cost_input_mode: 'amount',
                        actual_landed_cost_cad: null,
                    }),
                }),
            });
            return;
        }

        await route.fallback();
    });

    await page.goto(`/special-orders/${QUOTE_ORDER_ID}`, { waitUntil: 'domcontentloaded' });
    const merch = page.locator('.cao-detail__panel').filter({
        has: page.getByRole('heading', { name: 'Merchandiser' }),
    });
    await expect(merch.getByRole('heading', { name: 'Merchandiser' })).toBeVisible({
        timeout: 30_000,
    });
    await expect(merch.getByText(`Landed ${amountLanded} CAD`)).toBeVisible();

    const weightRadio = merch.locator('input[name="cao-quote-shipping-mode"][value="weight"]');
    const amountRadio = merch.locator('input[name="cao-quote-shipping-mode"][value="amount"]');

    await expect(amountRadio).toBeChecked();
    await weightRadio.check();
    await expect(weightRadio).toBeChecked();
    await expect(merch.locator('input[step="0.001"]')).toBeVisible();

    await page.waitForTimeout(4000);
    await expect(weightRadio).toBeChecked();
    await expect(amountRadio).not.toBeChecked();
    await expect(merch.locator('input[step="0.001"]')).toBeVisible();

    const kgInput = merch.locator('input[step="0.001"]');
    await kgInput.fill(String(weightKg));
    await kgInput.press('Tab');
    await page.waitForTimeout(4000);

    expect(pageErrors, pageErrors.join('\n')).toEqual([]);
    await expect(weightRadio).toBeChecked();
    await expect(amountRadio).not.toBeChecked();
    await expect(kgInput).toHaveValue(String(weightKg));
    await expect(merch.getByText(`= ${weightShippingRmb.toFixed(2)} RMB`)).toBeVisible();
    await expect(merch.getByText(`Landed ${weightLanded} CAD`)).toBeVisible();

    expect(patches.some((patch) => patch.shipping_cost_input_mode === 'weight')).toBe(true);
    const kgPatch = patches.find(
        (patch) => patch.shipping_cost_input_mode === 'weight' && patch.shipping_weight_kg != null,
    );
    expect(kgPatch?.shipping_weight_kg).toBe(String(weightKg));
    expect(kgPatch?.shipping_cost_amount).toBe(weightShippingRmb.toFixed(2));

    await merch.scrollIntoViewIfNeeded();
    await page.setViewportSize({ width: 1400, height: 900 });
    await merch.screenshot({
        path: 'test-results/quote-shipping-weight-desktop-1400.png',
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await merch.scrollIntoViewIfNeeded();
    await merch.screenshot({
        path: 'test-results/quote-shipping-weight-mobile-390.png',
    });
});

test('product name suggestions can be closed without choosing a row', async ({ page }) => {
    test.setTimeout(60_000);

    await page.route('**/api/v1/special-orders/filter-options', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    contact_media: [
                        { value: 'ig', label: 'Instagram' },
                        { value: 'fb', label: 'Facebook' },
                    ],
                    currencies: [
                        { value: 'CAD', label: 'CAD' },
                        { value: 'CNY', label: 'RMB' },
                    ],
                    receive_delay_units: [{ value: 'weeks', label: 'Weeks' }],
                    quote_statuses: [],
                    pricing_statuses: [],
                    lifecycle_statuses: [],
                },
            }),
        });
    });

    await page.route('**/api/v1/maintenance/special-order-pricing-caps', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: {
                    merchandiser_commission_cap_cad: '50.00',
                    opv_margin_cap_cad: '150.00',
                    default_shipping_cost_per_kg_cny: '29.00',
                },
            }),
        });
    });

    await page.route('**/api/v1/special-orders/product-name-suggestions**', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: [
                    {
                        source_key: 'argama',
                        source_name: 'Argama Hobby',
                        title: '30MM 1/144 EXM-15 Full Armor Portanova',
                        price_cad: '31.99',
                    },
                    {
                        source_key: 'argama',
                        source_name: 'Argama Hobby',
                        title: '30MF Class Up Armor (Liber Paladin)',
                        price_cad: '11.99',
                    },
                ],
            }),
        });
    });

    await page.route(`**/api/v1/special-orders/${SUGGEST_ORDER_ID}`, async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                data: lockedOrder({
                    id: SUGGEST_ORDER_ID,
                    product_name: 'Nether Emperor Full Armor',
                    offer_locked_at: null,
                    actual_product_cost_amount: null,
                    actual_shipping_cost_amount: null,
                    actual_landed_cost_cad: null,
                }),
            }),
        });
    });

    await page.goto(`/special-orders/${SUGGEST_ORDER_ID}`, { waitUntil: 'domcontentloaded' });
    const nameInput = page.getByRole('combobox', { name: 'Special order product name' });
    await expect(nameInput).toHaveValue('Nether Emperor Full Armor', { timeout: 30_000 });
    await nameInput.click();
    await expect(page.getByRole('listbox')).toBeVisible();
    await expect(page.getByRole('option').first()).toBeVisible();

    await page.getByRole('button', { name: 'Close name suggestions' }).click();
    await expect(page.getByRole('listbox')).toHaveCount(0);
    await expect(nameInput).toHaveValue('Nether Emperor Full Armor');

    await page.getByRole('heading', { name: 'Merchandiser' }).click();
    await nameInput.click();
    await expect(page.getByRole('listbox')).toHaveCount(0);

    await page.setViewportSize({ width: 1400, height: 900 });
    await page.screenshot({
        path: 'test-results/product-name-suggestions-closed-desktop-1400.png',
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
        path: 'test-results/product-name-suggestions-closed-mobile-390.png',
    });
});
