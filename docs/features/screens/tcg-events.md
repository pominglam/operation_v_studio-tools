# TCG Events (`/tcg-events`)

**Page:** `resources/js/pages/TcgEventsPage.vue`

## Summary

Operational directory of **Bandai TCG+ sanctioned events**, cached server-side until an operator triggers **`refresh`**.

Primary API reads:

| Action | Endpoint | Query/body highlights |
| --- | --- | --- |
| Load table | **`GET /api/v1/tcg/events`** | `per_page`, `start_date`, `search`, optional `status`, `format`, `hide_zero_applicants` (**1 or 0**) |
| Refresh remote dataset | **`POST /api/v1/tcg/events/refresh`** JSON body (**60_000 ms axios timeout**) | Sends **`start_date`**, **`street_address`** (defaults UI string **`montreal`** if blank), **`pref_code: CA-QC`**, **`country_code: CA`**, **`game_title_id: 16`**, **`limit: 100`** |

## Filters & refresh behavior

Reactive **`watch`** on **`[startDate, status, format, hideZeroApplicants]`** triggers **`fetchEvents()`** automatically.

| Field | Effect |
| --- | --- |
| **Search** (`search` ref) | **Not auto-watched** — apply via **Enter** in the search input or **`Search`** button (both call `fetchEvents`). |
| **Location** (`streetAddress`) | Edits the **`street_address`** body for **`Refresh`** only; does **not** change GET filtering until **`POST /refresh`**. |

`formatOptions` derives distinct **`format`** values from the cached `events` list for the **Format** `<select>`.

## Table columns

Store (name / phone / website), Location (address + Google Maps link), Event (linked **`event_url`**), Date/Time (Toronto-ish display), Format, Excerpt, Lottery method, Entry fee, Capacity, Applicants, Status.
