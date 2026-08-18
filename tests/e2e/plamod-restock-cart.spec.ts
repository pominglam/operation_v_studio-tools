import { expect, test } from '@playwright/test';

test('PLAMOD cart run can be queued, reviewed, rechecked, and dismissed', async ({ page }) => {
    let cartQueued = false;

    await page.route('**/api/v1/plamod/restock/proposal**', async (route) => {
        await route.fulfill({
            json: {
                data: {
                    snapshot: { sync_log_id: 1, synced_at: '2026-08-12T20:00:00Z', item_count: 1 },
                    shipping_percent: 5,
                    existing: [
                        {
                            product_uuid: 'e2e-product',
                            sku: 'E2E-CART-1',
                            product_name: 'E2E Cart Product',
                            barcode: null,
                            release_date: null,
                            release_date_label: null,
                            is_recent_release: false,
                            available_qty: 0,
                            maintain_qty: 3,
                            not_arrived_qty: 0,
                            reorder_qty: 3,
                            reorder_qty_override: null,
                            is_reorder_overridden: false,
                            proposed_qty: 3,
                            last_landed_cost: null,
                            new_landed_cost: {
                                product: '10.00',
                                shipping: '0.50',
                                landed: '10.50',
                            },
                            line_total: { product: '30.00', shipping: '1.50', landed: '31.50' },
                            cost_delta_high: false,
                            cost_delta_percent: null,
                            plamod_pdp_url: 'https://plamod.com/retailer/products/E2E-CART-1',
                        },
                    ],
                    new_products: [],
                    totals: {
                        units: 3,
                        product: '30.00',
                        shipping: '1.50',
                        landed: '31.50',
                        lines_with_missing_price: 0,
                    },
                    meta: {
                        existing_count: 1,
                        new_count: 0,
                        dismissed_count: 0,
                        undecided_new_count: 0,
                        included_new_count: 0,
                        new_missing_price_count: 0,
                    },
                },
            },
        });
    });
    await page.route('**/api/v1/plamod/restock/sync-status', async (route) => {
        await route.fulfill({ json: { data: { status: 'completed', counts: {} } } });
    });
    await page.route('**/api/v1/plamod/restock/cart-run-status', async (route) => {
        await route.fulfill({
            json: cartQueued
                ? {
                      data: {
                          status: 'completed',
                          cart_run_id: 77,
                          all_verified: false,
                          report: {
                              cart_url: 'https://plamod.com/retailer/cart',
                              summary: {
                                  requested_lines: 1,
                                  verified: 0,
                                  partial: 0,
                                  over_added: 1,
                                  missing: 0,
                                  add_failed: 0,
                                  already_satisfied: 0,
                                  all_verified: false,
                              },
                              lines: [
                                  {
                                      sku: 'E2E-CART-1',
                                      product_name: 'E2E Cart Product',
                                      requested_qty: 3,
                                      cart_qty_before: 4,
                                      cart_qty_after: 4,
                                      cart_qty_added: 0,
                                      verification_status: 'over_added',
                                  },
                              ],
                          },
                      },
                  }
                : { data: { status: 'never', cart_run_id: null, counts: {} } },
        });
    });
    await page.route('**/api/v1/plamod/restock/cart-run', async (route) => {
        expect(route.request().postDataJSON()).toEqual({ skus: ['E2E-CART-1'] });
        cartQueued = true;
        await route.fulfill({ json: { data: { ok: true, cart_run_id: 77, line_count: 1 } } });
    });
    await page.route('**/api/v1/plamod/restock/cart-run-recheck', async (route) => {
        await route.fulfill({
            json: {
                data: {
                    ok: true,
                    all_verified: true,
                    report: {
                        cart_url: 'https://plamod.com/retailer/cart',
                        rechecked_at: '2026-08-12T21:00:00Z',
                        summary: {
                            requested_lines: 1,
                            verified: 1,
                            partial: 0,
                            over_added: 0,
                            missing: 0,
                            add_failed: 0,
                            already_satisfied: 0,
                            all_verified: true,
                        },
                        lines: [
                            {
                                sku: 'E2E-CART-1',
                                product_name: 'E2E Cart Product',
                                requested_qty: 3,
                                cart_qty_before: 0,
                                cart_qty_after: 3,
                                cart_qty_added: 3,
                                verification_status: 'verified',
                            },
                        ],
                    },
                },
            },
        });
    });

    page.on('dialog', (dialog) => dialog.accept());
    await page.goto('/restocking/plamod');
    await page.getByTestId('restock-existing-cart-select-E2E-CART-1').check();
    await page.getByTestId('restock-add-to-plamod-cart').click();

    await expect(page.getByTestId('restock-cart-report-row-E2E-CART-1')).toContainText(
        'Over-added',
    );
    await expect(page.getByTestId('restock-cart-retry-failed')).toContainText(
        'Retry remaining (1)',
    );
    await page.getByTestId('restock-cart-retry-failed').click();
    await page.getByTestId('restock-cart-recheck').click();
    await expect(page.getByTestId('restock-cart-report-row-E2E-CART-1')).toContainText('Verified');

    await page.getByTestId('restock-cart-dismiss').click();
    await expect(page.getByTestId('restock-cart-report')).toBeHidden();
});
