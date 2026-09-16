# Marketing notes

**Route:** `/marketing-notes`  
**Page:** `resources/js/pages/StoreMarketingNotesPage.vue`  
**Nav:** **Events** → **Marketing notes** (admin only). Store events page also links here.

## Purpose

Dated log of marketing (posts, videos, ads) from the sheet **Other** column. Not tied to that day’s orders or Event $.

## User actions

| Action | Behavior |
| --- | --- |
| Open page | Lists notes, newest date first. |
| Search | Filters name and notes. |
| Add / Edit | When, what, notes. |
| Delete | Confirm, then remove the note. |

## API

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/v1/store-marketing-notes` | Optional `search`. `{ data: StoreMarketingNote[] }` |
| POST | `/api/v1/store-marketing-notes` | 201. Body: `name`, `happened_on`, `notes` |
| PATCH | `/api/v1/store-marketing-notes/{uuid}` | Same body. 404 if missing. |
| DELETE | `/api/v1/store-marketing-notes/{uuid}` | 204. 404 if missing. |

`id` in JSON is the row UUID.

## Backend

| Piece | Role |
| --- | --- |
| `StoreMarketingNoteService` | Create / update / delete |
| `store_marketing_notes` | `happened_on`, `name`, `notes` |
