DELETE v FROM product_taxonomy_verifications v
INNER JOIN products p ON p.id = v.product_id
WHERE p.sku LIKE 'E2E-%'
   OR p.sku LIKE 'ARCH-UI-%'
   OR p.sku LIKE 'BR-UI-%'
   OR p.sku LIKE '%UI-TEST%'
   OR UPPER(p.description) LIKE '%UI TEST KIT%';

DELETE q FROM product_price_quotes q
INNER JOIN products p ON p.id = q.product_id
WHERE p.sku LIKE 'E2E-%'
   OR p.sku LIKE 'ARCH-UI-%'
   OR p.sku LIKE 'BR-UI-%';

DELETE a FROM product_external_assets a
INNER JOIN products p ON p.id = a.product_id
WHERE p.sku LIKE 'E2E-%'
   OR p.sku LIKE 'ARCH-UI-%'
   OR p.sku LIKE 'BR-UI-%';

DELETE c FROM product_external_contents c
INNER JOIN products p ON p.id = c.product_id
WHERE p.sku LIKE 'E2E-%'
   OR p.sku LIKE 'ARCH-UI-%'
   OR p.sku LIKE 'BR-UI-%';

DELETE FROM plamod_instock_items WHERE sku LIKE 'E2E-%' OR sku LIKE 'ARCH-UI-%' OR sku LIKE 'BR-UI-%';
DELETE FROM plamod_restock_sku_decisions WHERE sku LIKE 'E2E-%' OR sku LIKE 'ARCH-UI-%' OR sku LIKE 'BR-UI-%';
DELETE FROM plamod_restock_planned_maintain WHERE sku LIKE 'E2E-%' OR sku LIKE 'ARCH-UI-%' OR sku LIKE 'BR-UI-%';
DELETE FROM plamod_restock_reorder_overrides WHERE sku LIKE 'E2E-%' OR sku LIKE 'ARCH-UI-%' OR sku LIKE 'BR-UI-%';

DELETE FROM products
WHERE sku LIKE 'E2E-%'
   OR sku LIKE 'ARCH-UI-%'
   OR sku LIKE 'BR-UI-%'
   OR sku LIKE '%UI-TEST%'
   OR UPPER(description) LIKE '%UI TEST KIT%';
