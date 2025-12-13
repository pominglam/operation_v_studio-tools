# Local development (Docker for PHP + MySQL)

Per project workflow, run **PHP** and **MySQL** in Docker containers, and run **Node/Vite** locally.

## Start services

From the repo root:

```powershell
docker compose up -d --build
```

This starts:
- **PHP app** at `http://localhost:8020`
- **MySQL** exposed on `127.0.0.1:3308`

## Install PHP deps (in container)

```powershell
docker compose run --rm php composer install
```

## Run migrations (in container)

```powershell
docker compose run --rm php php artisan migrate
```

## One-time transfer: SQLite → MySQL

If you previously ran the app on SQLite (e.g. `php artisan serve`) and you want to move that data into MySQL:

```powershell
docker compose run --rm php php artisan db:transfer-sqlite-to-mysql --truncate --yes
```

## Run queue worker (in container)

Price research can enqueue jobs. In a separate terminal:

```powershell
docker compose run --rm php php artisan queue:work --queue=price_research
```

> Note: In `APP_ENV=local`, the app also supports a local inline fallback when a run is stuck `queued`.

## Frontend dev (local Node)

In a local terminal (NOT Docker):

```powershell
npm install
npm run dev
```

The PHP container is configured with:
- `VITE_DEV_SERVER_URL=http://host.docker.internal:5173`

## MySQL connection (Workbench/TablePlus)

- Host: `127.0.0.1`
- Port: `3308`
- User: `pricing_tool`
- Password: `pricing_tool`
- Database: `pricing_tool`

## Stop services

```powershell
docker compose down
```


