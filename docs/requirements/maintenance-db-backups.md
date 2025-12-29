# Maintenance — Database Backups & Restore (Requirements)

## Goal
Provide a safe, repeatable way for admins to:

- Create a **database backup** with a human description
- View recent backups
- Restore from a selected backup (with explicit confirmation)

## API (v1)

### List backups
`GET /api/v1/maintenance/db-backups?limit={n}`

Response: `{ data: DatabaseBackup[] }`

`DatabaseBackup` fields:
- `uuid` (string)
- `driver` (`mysql` | `sqlite`)
- `filename` (string)
- `description` (string|null)
- `created_by` (`manual` | `system`)
- `size_bytes` (int|null)
- `created_at` (ISO-8601 string)

### Create backup
`POST /api/v1/maintenance/db-backups`

Body:
- `description` (string, required, max length enforced by request validation)

Response:
- `201` with `{ data: DatabaseBackup }`

### Restore backup
`POST /api/v1/maintenance/db-backups/restore`

Body:
- `backup_uuid` (string, required)

Responses:
- `200` on success
- `404` when `backup_uuid` is not found
- `422` on validation errors

## UI (Maintenance page)

### Database backups section
- **Create**:
  - Input: description
  - Button: “Create backup”
  - Shows success/error feedback
- **Restore**:
  - Select: a backup from the list
  - Button: “Restore selected backup”
  - Requires confirmation dialog
  - Shows success/error feedback

## Safety / Operational requirements
- MySQL deployments must have `mysqldump` available to create backups and `mysql` available for restore in the PHP runtime container.
- Restore is destructive and must require explicit confirmation in the UI.
- Backups created automatically by the system should use `created_by=system` and set a descriptive `description`.

## Tests

### Schema
- `tests/Feature/Database/DatabaseBackupsSchemaTest.php`
  - Ensures `database_backups` table exists and expected columns are present.

### API
- `tests/Feature/Api/V1/DatabaseBackupsApiTest.php`
  - Lists backups
  - Creates backup with description (service mocked)
  - Restore unknown UUID returns 404
  - Validates create payload (422)


