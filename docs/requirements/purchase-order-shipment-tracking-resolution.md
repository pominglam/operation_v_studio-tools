# Purchase order shipment tracking resolution

## Goal

Replace unconditional 17TRACK links with asynchronously verified links to a credential-free public
tracking aggregator that has returned real shipment events for the specific tracking number.

## Required behavior

1. The purchase-order list loads without waiting for external tracking sites.
2. Each stored tracking number is initially plain text with a loading indicator while resolution is
   queued or running.
3. A background job asks the tracking browser worker to try supported aggregators in order.
4. The worker accepts a provider only when its result page contains shipment-specific tracking
   events. A generic page, no-result page, quota warning, CAPTCHA, or HTTP success alone is not a
   match.
5. The first successful provider is saved and shown as the only clickable link for that tracking
   number.
6. A saved successful result is reused without probing providers again.
7. No-match and technical-failure results remain plain text and may be retried after a cooldown.
8. Provider failures must not block trying the next provider.
9. Editing a PO to add a new tracking number creates an independent resolution. Reusing the same
   normalized tracking number reuses its cached resolution.

## Architecture

- Laravel stores one global resolution row per normalized tracking number.
- A bulk API returns cached statuses and queues eligible unresolved numbers.
- The Vue PO grid calls that API only after the PO list has rendered, then polls while any visible
  resolution is queued or running.
- A queued Laravel job calls an isolated Playwright tracking worker. The HTTP request that loads the
  PO list never calls an external tracking site.
- The worker returns only `resolved` with a provider and public tracking URL, `not_found`, or a
  technical failure.

## Status contract

| Status                 | Grid behavior                               | Retry                        |
| ---------------------- | ------------------------------------------- | ---------------------------- |
| `queued` / `resolving` | Plain tracking number plus spinner          | Poll                         |
| `resolved`             | One clickable provider link                 | Never automatically re-probe |
| `not_found`            | Plain tracking number with unavailable hint | After no-match cooldown      |
| `failed`               | Plain tracking number with unavailable hint | After short failure cooldown |

## Supported providers

Provider order is evidence-driven and stops on the first real event match. Verified against sample
numbers `520704842993` (Purolator / UBI last-mile) and `520701651454` (no public match):

1. **17TRACK** — tried first via `t.17track.net`; same URL format previously used for direct links.
2. **Kuaidi100** — strong hit rate, clear Chinese no-result copy, no quota observed.
3. **AfterShip** — strong when under free quota; reject on “Quota Exceeded”.
4. **Ship24** — direct `tracking?p=` URL; Purolator label events when others miss.
5. **ParcelsApp** — direct `/en/tracking/{number}` URL; reject on “No information about your package”.

Provider-specific selectors and no-result/quota/CAPTCHA signals live only in the browser worker.

## Safety and observability

- Normalize and validate tracking numbers before persistence or browser navigation.
- Allow only configured provider hosts in returned links.
- Apply connection/request timeouts and bounded retries.
- Log provider attempts without credentials, cookies, or page bodies.
- Persist a condensed error summary and attempt timestamps for operator diagnosis.
