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

## Scheduled backups & retention

The Laravel scheduler (Docker `scheduler` service / `schedule:work`) runs:

| Job | Schedule (America/Toronto) | Command |
| --- | --- | --- |
| Daily backup + off-site push | **02:30** (host orchestrator) | `pricing-tool-backup-and-offsite-push.sh` — backup then push latest ZIP |
| Daily backup (in-container) | 02:30 | `db:backup` — **skipped when** `DB_BACKUP_HOST_ORCHESTRATOR=true` |
| Weekly purge (local) | Sunday 03:30 | `db:backup:purge --yes` |

Disable in-container daily backup when using host orchestrator: `DB_BACKUP_HOST_ORCHESTRATOR=true`.

### Retention policy (config: `config/database_backup.php`)

| Tier | Default | Behavior |
| --- | --- | --- |
| Recent | 14 days (`DB_BACKUP_RETENTION_RECENT_DAYS`) | Keep every backup |
| Weekly history | 180 days (`DB_BACKUP_RETENTION_WEEKLY_DAYS`) | Keep one backup per ISO week |
| Safety floor | 5 (`DB_BACKUP_RETENTION_MINIMUM`) | Never purge below N newest backups |

Manual preview: `php artisan db:backup:purge --dry-run`.

Purged artifacts: ZIP file under `storage/backups/` plus `database_backups` row.

### Before migrations (lean prod)

On production-like MySQL (including local Docker treated as prod):

1. `php artisan db:backup --yes --description="Pre-migrate …" --created-by=system`
2. `php artisan migrate --pretend` — review SQL
3. Then `php artisan migrate`

Agents run this checklist **autonomously** — backup UUID is the rollback/undo; operator approval is not required. See **`operator-expectations.mdc`**.

### Off-site copy (consolidation droplet)

Automated push **PC → droplet** (not the same path as ATA daily pull — avoids circular backup):

| Item | Value |
| --- | --- |
| Source | `storage/backups/*.zip` (latest only per daily run) |
| Destination | `134.209.213.143:/srv/stack/backups/offsite/pricing-tool/` |
| Push schedule | Sequential after backup — **02:30** host orchestrator |
| Droplet off-site | 3d all + weekly 45d, min 2, max 4; purge after each push + droplet cron |

Setup and ops: `local-llm/docs/server-consolidation/pricing-tool-offsite-backup.md`, manifest `~/workspace/Backups/OPERATION-V/manifest.md`.

MySQL **binlog** (when enabled) is a separate ops-level safety net for point-in-time recovery; it does not replace scheduled mysqldump backups.

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


