# Foodjett

Foodjett is a decoupled Symfony JSON API and React single-page application. The
repository includes a complete Docker Compose stack for MariaDB, Mercure, the
Symfony API, the scheduler worker, and the production-built frontend.

## Run the complete stack with Docker

### Requirements

- Docker Desktop with Docker Compose v2
- Ports `8080`, `8000`, and `3000` available on the host (or change them in
  the root `.env` file)

### Fresh-clone setup

1. Create the local Compose environment file:

   ```powershell
   Copy-Item .env.example .env
   ```

   On macOS/Linux:

   ```bash
   cp .env.example .env
   ```

2. Replace every `replace-with-...` value in `.env`. Use URL-safe
   alphanumeric characters for `MARIADB_PASSWORD`, because it is embedded in
   Symfony's `DATABASE_URL`. The root `.env` is ignored by Git.

3. Build and start all five services:

   ```bash
   docker compose up -d --build
   ```

4. Confirm that every service is running and healthy:

   ```bash
   docker compose ps
   ```

5. Create the first administrator:

   ```bash
   docker compose exec api php bin/console app:make-admin "Foodjett Admin" admin@example.test "ChangeThisPassword123!"
   ```

6. Open the application:

   - Web application: <http://localhost:8080>
   - Direct API access and health check: <http://localhost:8000/api/health>
   - Mercure hub: <http://localhost:3000/.well-known/mercure>

The API entrypoint generates the JWT signing key pair in a persistent volume
when it is missing and runs all Doctrine migrations before Apache starts. No
separate migration command or JWT setup is required for the normal startup.

### Services

| Service | Purpose | Host exposure |
| --- | --- | --- |
| `mariadb` | MariaDB 10.4.32 application database | Internal only |
| `mercure` | Server-Sent Events hub | `localhost:3000` |
| `api` | Symfony API under Apache/PHP 8.3 | `localhost:8000` |
| `scheduler` | Rider-pool escalation scheduler consumer | Internal only |
| `web` | React production build served by nginx | `localhost:8080` |

The nginx frontend proxies `/api`, `/uploads`, and `/.well-known/mercure` over
the Compose network. Browser requests therefore share one public origin, while
Symfony publishes internally to `http://mercure/.well-known/mercure`. The API's
`MERCURE_PUBLIC_URL` remains browser-reachable at
`http://localhost:8080/.well-known/mercure`; using the internal service name in
a browser would not work because Docker DNS is only available inside the
Compose network.

MariaDB data, uploaded files, Mercure/Caddy data, and generated JWT keys use
named volumes, so a normal stop/restart keeps demo data.

## Common commands

Follow logs for the whole stack:

```bash
docker compose logs -f
```

Follow the operational services only:

```bash
docker compose logs -f api scheduler mercure
```

Run migrations manually when diagnosing startup:

```bash
docker compose exec api php bin/console doctrine:migrations:migrate --no-interaction
```

Inspect the scheduler registered by Symfony:

```bash
docker compose exec api php bin/console debug:scheduler
```

Run an API console command:

```bash
docker compose exec api php bin/console <command>
```

Stop the stack without deleting data:

```bash
docker compose down
```

Rebuild the frontend after source changes (the Docker target is a static
production build and intentionally has no hot reload):

```bash
docker compose up -d --build web
```

## Clean reset

This permanently removes the Dockerized database, uploads, Mercure state, and
JWT keys. It does not touch a native XAMPP/MariaDB database.

```bash
docker compose down -v --remove-orphans
docker compose up -d --build
```

Migrations are applied automatically after MariaDB passes its health check.
The project currently has no Doctrine fixtures loader; create demo accounts
through registration, approve restaurant/rider applications through the admin
UI, and use `app:make-admin` for the initial administrator.

## Environment and secrets

- Commit `.env.example`, never the root `.env`.
- Do not place real secrets in `compose.yaml`, either Dockerfile, or the
  subprojects' committed `.env` defaults.
- `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD`, `APP_SECRET`,
  `JWT_PASSPHRASE`, and `MERCURE_JWT_SECRET` must be changed for each clone.
- If a public port changes, update its corresponding public URL in `.env`.
- The API uses `mariadb` and `mercure` as internal hostnames; `localhost` is
  reserved for URLs that a browser or host-side tool must reach.

## Native development

The existing native workflows remain available (`symfony server:start` in
`api/` and `npm run dev` in `web/`). The old standalone `api/compose.yaml` was
removed: use the root `compose.yaml` when running containerized services.
