import { expect, test } from './fixtures';

test('draft PO flow: status, add SKU lines, and export csv', async ({
  page,
  request,
  trackE2EProductId,
  trackE2EPurchaseOrderId,
}) => {
  const uniq = String(Date.now());
  const skuA = `E2E-DRFT-A-${uniq}`;
  const skuB = `E2E-DRFT-B-${uniq}`;

  const p1Res = await request.post('/api/v1/products', {
    data: { sku: skuA, description: `Draft Product A ${uniq}`, vendor: 'Stedi' },
  });
  expect(p1Res.ok()).toBeTruthy();
  const p1 = (await p1Res.json()) as any;
  const p1Id = p1?.data?.id as string | undefined;
  trackE2EProductId(p1Id);

  const p2Res = await request.post('/api/v1/products', {
    data: { sku: skuB, description: `Draft Product B ${uniq}`, vendor: 'Stedi' },
  });
  expect(p2Res.ok()).toBeTruthy();
  const p2 = (await p2Res.json()) as any;
  const p2Id = p2?.data?.id as string | undefined;
  trackE2EProductId(p2Id);

  const draftRes = await request.post('/api/v1/purchase-orders/drafts/create-from-products', {
    data: { ids: [p1Id] },
  });
  expect(draftRes.ok()).toBeTruthy();
  const draft = (await draftRes.json()) as any;
  const poUuid = draft?.purchase_order_uuid as string | undefined;
  expect(poUuid).toBeTruthy();
  trackE2EPurchaseOrderId(poUuid);

  await page.goto(`/purchase-orders/${poUuid}`);
  await expect(page.getByText('Status: Draft')).toBeVisible();

  await page.getByPlaceholder('SKU-001\nSKU-002').fill(`${skuB}\nMISSING-SKU-123`);
  await page.getByRole('button', { name: 'Add products' }).click();
  await expect(page.getByText(/Added 1 line\(s\)\./)).toBeVisible();
  await expect(page.getByText(skuB, { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'PO Lines' }).first()).toBeVisible();

  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: 'Export PO lines CSV' }).click(),
  ]);
  expect(download.suggestedFilename()).toContain(poUuid.slice(0, 8));
});

