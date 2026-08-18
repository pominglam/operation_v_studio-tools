const test = require('node:test');
const assert = require('node:assert/strict');
const {
    buildVerificationStatus,
    summarizeReport,
    parseMoqFromBlockText,
    parseCartRowQty,
    isCartSnapshotCredible,
    buildExtraCartLines,
    buildVerificationReport,
    parseRetailerCartItemBadgeCount,
    cartLineAdjustmentAction,
    cartLineMutationSurface,
    cartLineStepperPlan,
    cartLineStepperSelector,
    isCartMutationContainer,
    parsePreorderArrivedQty,
    isPreorderArrivedContainerText,
    isSkuScopedCartContainer,
    cartLineTargetInStockQty,
    isRestockCartRequestedQty,
} = require('../src/plamod-restock-cart');

test('parseCartRowQty prefers TOTAL over combobox', () => {
    assert.equal(parseCartRowQty('IN-STOCK MOQ: 1 TOTAL010 PRICE', '10'), 10);
    assert.equal(parseCartRowQty('IN-STOCK TOTAL00', '0'), 0);
    assert.equal(parseCartRowQty('SKU:5058815', '10'), 10);
    assert.equal(parseCartRowQty('SKU: 5069191 TOTAL20', '20', ['2', '0']), 2);
    assert.equal(parseCartRowQty('SKU: 5069191 MOQ: 12TOTAL20', '', [], ['2', '0']), 2);
});

test('parsePreorderArrivedQty reads the ordered quantity before its label', () => {
    assert.equal(
        parsePreorderArrivedQty([
            'PREORDER ARRIVED',
            '6240',
            'RECEIVED: AUG 17',
            '1',
            '1',
            '2',
            'ORDERED',
        ]),
        2,
    );
    assert.equal(parsePreorderArrivedQty(['IN-STOCK', '0', 'TOTAL']), 0);
});

test('arrived preorder container detection handles flattened numeric labels', () => {
    assert.equal(
        isPreorderArrivedContainerText('PREORDER ARRIVED 6240 RECEIVED: AUG 17 MOQ: 12ORDERED2'),
        true,
    );
    assert.equal(isPreorderArrivedContainerText('IN-STOCK MOQ: 10TOTAL00'), false);
});

test('arrived preorder parsing rejects parent containers spanning other SKUs', () => {
    assert.equal(
        isSkuScopedCartContainer('5073703', [
            '/retailer/products/5073703',
            '/retailer/products/5073703',
        ]),
        true,
    );
    assert.equal(
        isSkuScopedCartContainer('5073703', [
            '/retailer/products/5073703',
            '/retailer/products/5068706',
        ]),
        false,
    );
});

test('new products subtract arrived preorders from the required in-stock quantity', () => {
    assert.equal(cartLineTargetInStockQty('new', 2, 2), 0);
    assert.equal(cartLineTargetInStockQty('new', 3, 2), 1);
    assert.equal(cartLineTargetInStockQty('existing', 2, 2), 2);
});

test('buildVerificationStatus marks verified when cart reached requested qty from zero', () => {
    assert.equal(buildVerificationStatus(10, 0, 10, false), 'verified');
});

test('buildVerificationStatus marks verified when a partially filled cart reaches requested total', () => {
    assert.equal(buildVerificationStatus(10, 3, 10, false), 'verified');
});

test('buildVerificationStatus marks already satisfied only at the exact requested total', () => {
    assert.equal(buildVerificationStatus(10, 10, 10, false), 'already_satisfied');
    assert.equal(buildVerificationStatus(10, 11, 11, false), 'over_added');
});

test('parseMoqFromBlockText handles normal and concatenated MOQ labels', () => {
    assert.equal(parseMoqFromBlockText('IN-STOCK MOQ: 10 TOTAL 0'), 10);
    assert.equal(parseMoqFromBlockText('IN-STOCK MOQ: 110TOTAL010 PRICE'), 1);
    assert.equal(parseMoqFromBlockText('IN-STOCK MOQ: 10TOTAL00 PRICE'), 1);
    assert.equal(parseMoqFromBlockText('IN-STOCK MOQ: 10TOTAL010 PRICE'), 10);
    assert.equal(parseMoqFromBlockText('IN-STOCK MOQ: 1 TOTAL 0'), 1);
    assert.equal(
        parseMoqFromBlockText('IN-STOCKCARTON: 8PACK: 1 MOQ: 12TOTAL02PRICE: $66.28TOTAL: $132.56'),
        1,
    );
    assert.equal(parseMoqFromBlockText('IN-STOCK MOQ: 11TOTAL010 PRICE', 1), 1);
    assert.equal(parseMoqFromBlockText('IN-STOCK MOQ: 1220TOTAL020 PRICE', 20), 12);
});

test('buildVerificationStatus marks exact cart delta as verified', () => {
    assert.equal(buildVerificationStatus(10, 0, 10, false), 'verified');
    assert.equal(buildVerificationStatus(2, 0, 2, false), 'verified');
});

test('buildVerificationStatus marks over-added cart delta', () => {
    assert.equal(buildVerificationStatus(10, 0, 11, false), 'over_added');
});

test('buildVerificationStatus marks partial cart delta', () => {
    assert.equal(buildVerificationStatus(5, 0, 2, false), 'partial');
});

test('buildVerificationStatus marks missing when nothing added', () => {
    assert.equal(buildVerificationStatus(2, 0, 0, false), 'missing');
});

test('buildVerificationStatus marks add_failed when add step failed', () => {
    assert.equal(buildVerificationStatus(2, 0, 0, true), 'add_failed');
});

test('cartLineAdjustmentAction converges missing, partial, and over-added lines', () => {
    assert.equal(cartLineAdjustmentAction(0, 3), 'create');
    assert.equal(cartLineAdjustmentAction(2, 3), 'adjust');
    assert.equal(cartLineAdjustmentAction(4, 3), 'adjust');
    assert.equal(cartLineAdjustmentAction(3, 3), 'exact');
});

test('cart adjustments mutate the existing cart row instead of adding again from PDP', () => {
    assert.equal(cartLineMutationSurface('create'), 'pdp');
    assert.equal(cartLineMutationSurface('adjust'), 'cart');
    assert.equal(cartLineMutationSurface('exact'), 'none');
});

test('cart stepper plan reaches lower and higher exact quantities', () => {
    assert.deepEqual(cartLineStepperPlan(4, 1), { direction: 'minus', clicks: 3 });
    assert.deepEqual(cartLineStepperPlan(2, 3), { direction: 'plus', clicks: 1 });
    assert.deepEqual(cartLineStepperPlan(3, 3), { direction: 'none', clicks: 0 });
});

test('cart stepper selector skips disabled preorder controls', () => {
    assert.equal(cartLineStepperSelector('minus'), 'button:has(svg.lucide-minus):not([disabled])');
});

test('cart mutation row accepts PLAMOD stepper-only in-stock controls', () => {
    assert.equal(isCartMutationContainer('SKU: 5057396 IN-STOCK TOTAL 2', false, true, true), true);
    assert.equal(
        isCartMutationContainer('SKU: 5057396 PREORDER ARRIVED ORDERED 2', false, false, false),
        false,
    );
});

test('summarizeReport sets all_verified only when every line verified', () => {
    const summary = summarizeReport([
        { verification_status: 'verified' },
        { verification_status: 'verified' },
    ]);
    assert.equal(summary.all_verified, true);
    assert.equal(summary.verified, 2);
});

test('summarizeReport fails all_verified when any line over-added', () => {
    const summary = summarizeReport([
        { verification_status: 'verified' },
        { verification_status: 'over_added' },
    ]);
    assert.equal(summary.all_verified, false);
    assert.equal(summary.over_added, 1);
});

test('parseRetailerCartItemBadgeCount prefers cart nav badge counts', () => {
    assert.equal(
        parseRetailerCartItemBadgeCount(
            'Dashboard Products Preorders Cart (131) Shopping Cart Active 131 items',
        ),
        131,
    );
    assert.equal(parseRetailerCartItemBadgeCount('Checkout (131 items) Refresh'), 131);
});

test('buildExtraCartLines lists cart SKUs not in requested order', () => {
    const extra = buildExtraCartLines([{ sku: '111', requested_qty: 2 }], {
        111: 2,
        999: 5,
        888: 0,
    });
    assert.deepEqual(extra, [{ sku: '999', cart_qty: 5 }]);
});

test('buildVerificationReport flags extra cart lines and order_matches_cart', () => {
    const report = buildVerificationReport(
        'https://plamod.com',
        [{ sku: '111', requested_qty: 2, add_status: 'order_verify' }],
        {},
        { 111: 2, 999: 1 },
        { scope: 'full_order', verified_at: '2026-08-15T00:00:00.000Z' },
    );
    assert.equal(report.summary.all_verified, true);
    assert.equal(report.summary.extra_cart_lines, 1);
    assert.equal(report.summary.order_matches_cart, false);
    assert.deepEqual(report.extra_cart_lines, [{ sku: '999', cart_qty: 1 }]);
    assert.equal(report.scope, 'full_order');
});

test('buildVerificationReport exposes arrived preorder separately from in-stock cart qty', () => {
    const report = buildVerificationReport(
        'https://plamod.com',
        [{ sku: '5067248', requested_qty: 1, add_status: 'order_verify' }],
        {},
        { 5067248: 0 },
        { preorder_arrived: { 5067248: 2 } },
    );

    assert.equal(report.lines[0].requested_qty, 1);
    assert.equal(report.lines[0].preorder_arrived_qty, 2);
    assert.equal(report.lines[0].cart_qty_after, 0);
    assert.equal(report.lines[0].verification_status, 'missing');
    assert.deepEqual(report.preorder_arrived, { 5067248: 2 });
});

test('cart mutations accept zero as an explicit removal target', () => {
    assert.equal(isRestockCartRequestedQty(0), true);
    assert.equal(isRestockCartRequestedQty('0'), true);
    assert.equal(isRestockCartRequestedQty(-1), false);
    assert.equal(isRestockCartRequestedQty('invalid'), false);
});

test('buildVerificationReport flags in-stock units above a new product total already covered by preorder', () => {
    const report = buildVerificationReport(
        'https://plamod.com',
        [{ sku: '5057396', source: 'new', requested_qty: 2, add_status: 'order_verify' }],
        {},
        { 5057396: 2 },
        { preorder_arrived: { 5057396: 2 } },
    );

    assert.equal(report.lines[0].target_instock_qty, 0);
    assert.equal(report.lines[0].preorder_arrived_qty, 2);
    assert.equal(report.lines[0].verification_status, 'over_added');
});

test('buildVerificationReport sets order_matches_cart when cart exactly matches order', () => {
    const report = buildVerificationReport(
        'https://plamod.com',
        [{ sku: '111', requested_qty: 2, add_status: 'order_verify' }],
        {},
        { 111: 2 },
        { scope: 'full_order' },
    );
    assert.equal(report.summary.order_matches_cart, true);
    assert.equal(report.extra_cart_lines.length, 0);
});

test('parseCartRowQty prefers combobox qty on cart rows', () => {
    assert.equal(parseCartRowQty('MOQ: 172TOTAL112PRICE: $6.11TOTAL: $439.92', '12'), 12);
});

test('parseCartRowQty reads TOTAL when combobox missing', () => {
    assert.equal(parseCartRowQty('IN-STOCK MOQ: 1 TOTAL010 PRICE', ''), 10);
});

test('parseCartRowQty falls back to TOTAL when combobox is stale at zero', () => {
    assert.equal(parseCartRowQty('IN-STOCK MOQ: 1 TOTAL010 PRICE', '0'), 10);
});

test('buildVerificationReport rebuilds report from stored cart_before baseline', () => {
    const { buildVerificationReport } = require('../src/plamod-restock-cart');
    const report = buildVerificationReport(
        'https://plamod.com',
        [
            {
                sku: '5058815',
                requested_qty: 10,
                add_status: 'added',
            },
        ],
        { 5058815: 0 },
        { 5058815: 10 },
        { rechecked_at: '2026-08-12T20:00:00.000Z' },
    );

    assert.equal(report.lines[0].verification_status, 'verified');
    assert.equal(report.lines[0].cart_qty_after, 10);
    assert.equal(report.summary.all_verified, true);
    assert.equal(report.rechecked_at, '2026-08-12T20:00:00.000Z');
});

test('buildVerificationReport does not mutate the original cart baseline', () => {
    const { buildVerificationReport } = require('../src/plamod-restock-cart');
    const baseline = { A: 2, B: 0 };
    const report = buildVerificationReport(
        'https://plamod.com',
        [
            { sku: 'A', requested_qty: 5, add_status: 'updated' },
            { sku: 'B', requested_qty: 2, add_status: 'updated' },
        ],
        baseline,
        { A: 5, B: 2 },
    );

    assert.deepEqual(baseline, { A: 2, B: 0 });
    assert.equal(report.lines[0].verification_status, 'verified');
    assert.equal(report.lines[0].cart_qty_added, 3);
    assert.equal(report.lines[1].verification_status, 'verified');
});

test('buildVerificationReport preserves PLAMOD messages until the line is verified', () => {
    const blocked = buildVerificationReport(
        'https://plamod.com',
        [
            {
                sku: '1234567',
                requested_qty: 1,
                add_status: 'rechecked',
                error_message: 'Requested 1 but PLAMOD MOQ is 2.',
            },
        ],
        {},
        {},
    );
    assert.equal(blocked.lines[0].verification_status, 'missing');
    assert.equal(blocked.lines[0].error_message, 'Requested 1 but PLAMOD MOQ is 2.');

    const fixed = buildVerificationReport('https://plamod.com', blocked.lines, {}, { 1234567: 1 });
    assert.equal(fixed.lines[0].verification_status, 'verified');
    assert.equal(fixed.lines[0].error_message, null);
});

test('isCartSnapshotCredible rejects an unexplained empty render', () => {
    assert.equal(isCartSnapshotCredible({}, false), false);
});

test('isCartSnapshotCredible accepts cart rows and explicit empty-cart state', () => {
    assert.equal(isCartSnapshotCredible({ 5068381: 1 }, false), true);
    assert.equal(isCartSnapshotCredible({}, true), true);
});
