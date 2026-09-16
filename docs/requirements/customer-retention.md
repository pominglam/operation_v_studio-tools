# Customer retention report

Identified Shopify buyers only (Layer B). Anonymous Quick Sale tickets stay on `/orders` (Layer A) and are not invented as people.

## Identity (R1–R3)

One person is the connected component of:

- **R1** same Shopify customer GID
- **R2** same email after trim + lowercase (empty ignored; optional denylist `SHOPIFY_CUSTOMER_EMAIL_DENYLIST`)
- **R3** same phone after digits-only, North-American leading `1` stripped, length ≥ 10 (empty ignored)

## Eligible orders

Same as `/orders` Eligible and the staff report: not cancelled, not voided. Spend is `subtotal_shop_amount` (before tax and shipping).

## Our labels

| Label | Rule |
| --- | --- |
| New | Exactly 1 eligible identified order |
| Repeat | 2+ (headline retention metric) |
| Loyal | 3+ (slice of Repeat; column shows Loyal instead of Repeat when 3+) |

## RFM

Computed on the **merged person**, using Shopify Admin names and R/FM rules. Scores are store quintiles (5 = top fifth of *this* store). `FM = floor((F + M) / 2)`. Prospects (no orders) are omitted.

Click an RFM name in the UI for the exact rule. Click ⓘ on the R/F/M column for Recency / Frequency / Monetary.

## Acquisition (same report)

There is no separate customer acquisition page. Each **Eastern Time (Montreal)** calendar month’s **New** count is people whose first identified eligible order fell in that month. **Returning** is people who bought again after an earlier month. Montreal and Toronto share the same Eastern clock, so month buckets match the rest of the ERP.

| Month column | Meaning |
| --- | --- |
| New | First identified order that month |
| Returning | Bought that month and already had an earlier identified order |
| Return rate | Returning ÷ buyers that month |
| Those new, now 2+ | Of that month’s New people, how many have 2+ orders as of today |
| Orders | Count of identified eligible orders in the month |
| Spend | Σ subtotal before tax/shipping for those orders |
| New spend | Spend that month from people whose first eligible order is that month |
| Returning spend | Spend that month from people who already had an earlier-month order |
| AOV | Spend ÷ orders |

## AOV

Person AOV = spend ÷ orders. Headline identified AOV = identified spend ÷ identified orders.

## Store rhythm (cadence)

Hobby shops are non-contractual: there is no cancel button. Headline status is vs **this store’s typical gap**, not vs a starter-haul mean.

1. Collect consecutive interpurchase gaps among identified people.
2. Keep gaps of **14+ days** (drops same-week binge / starter-haul tickets).
3. Store median = median of those typical gaps. Recalculated from live data each snapshot (not hardcoded).
4. **Due** after 1 × that median. **Lapsed** after `max(60, 2 × median)`.

| Status | Rule |
| --- | --- |
| On cadence | Days since last dated order is below the store median |
| Due | Days since last is at least the store median and at most the lapsed line |
| Lapsed | Days since last is past `max(60, 2 × median)` |

Anyone with at least one dated eligible order is scored, including New (1 order). People with no dated order stay blank.

## Own pace (2× personal gap)

Kept as a comparison note, not the headline. For 2+ dated orders: average gap = mean days between consecutive orders (minimum 1 day). **Churned** when days since last order > 2 × that person’s own average gap. One-order people are not scored. Starter hauls make this fire too early — use store rhythm for the list.

## Later (not in this report)

Events vs normal, seasonality labels, household split, VIP-inside-Champions.
