# Foodjett

Foodjett is a food-delivery platform built with Symfony 7.4. It has four roles:
customers order from restaurants, restaurants manage menus and orders, riders
accept and deliver orders, and administrators approve partners and run the
platform. Order tracking, chat and the rider order pool update live through a
Mercure hub.

The Symfony application lives at the repository root. The user interface is
moving from a separate React single-page application to Twig templates
rendered by Symfony; during that transition the JSON endpoints under `/api`
remain in place.

## Run the stack with Docker

### Requirements

- Docker Desktop with Docker Compose v2.24 or newer
- Host ports `8080` and `3000` available

### First-time setup

1. Create the local secrets file from the template:

   ```powershell
   Copy-Item .env.docker.example .env.docker.local
   ```

   On macOS/Linux: `cp .env.docker.example .env.docker.local`

2. Replace every `change-me` value in `.env.docker.local` with a long random
   value. Use URL-safe alphanumeric characters for `MARIADB_PASSWORD`, repeat it
   inside `DATABASE_URL`, and give the three Mercure keys the same value.
   `.env.docker.local` is gitignored and never copied into the image.

3. Build and start the four services:

   ```bash
   docker compose up -d --build
   docker compose ps
   ```

4. Create the first administrator:

   ```bash
   docker compose exec app php bin/console app:make-admin "Foodjett Admin" admin@example.test "<choose-a-password>"
   ```

5. Open the application at <http://localhost:8080>. The health check is
   <http://localhost:8080/api/health>.

On startup the `app` container generates the JWT key pair in a persistent
volume when it is missing and runs all Doctrine migrations before Apache
starts.

### Services

| Service | Purpose | Host exposure |
| --- | --- | --- |
| `mariadb` | MariaDB 10.4.32 application database | Internal only |
| `mercure` | Mercure 1.1.0 Server-Sent Events hub | `localhost:3000` |
| `app` | Symfony under Apache with PHP 8.3 | `localhost:8080` |
| `scheduler` | Messenger worker that runs the rider-pool escalation schedule | Internal only |

Apache reverse-proxies `/.well-known/mercure` to the hub, so the browser reaches
the application and Mercure on the same origin
(`http://localhost:8080/.well-known/mercure`). Symfony publishes to the hub
internally at `http://mercure/.well-known/mercure`.

MariaDB data, uploaded files, Mercure state and the JWT keys use named volumes,
so stopping and restarting keeps data. To use other host ports, set
`WEB_PORT` (and `PUBLIC_WEB_URL` to match) in your shell before
`docker compose up`.

### Common commands

```bash
docker compose logs -f app scheduler mercure          # follow the logs
docker compose exec app php bin/console <command>     # run a console command
docker compose exec app php bin/console debug:scheduler
docker compose down                                   # stop, keep data
docker compose down -v --remove-orphans               # stop and DELETE all data
```

## Native development

Native development uses PHP 8.2 or newer with the `bcmath`, `intl`,
`pdo_mysql` and `zip` extensions, Composer, the Symfony CLI, and a local
MariaDB (for example XAMPP).

1. Install dependencies: `composer install`
2. Create `.env.local` with your local `DATABASE_URL` (and `APP_SECRET`). It is
   gitignored.
3. Create the schema: `php bin/console doctrine:migrations:migrate`
4. Start the Mercure hub only: `docker compose up -d mercure` (listens on
   `http://localhost:3000/.well-known/mercure`).
5. Start the web server: `symfony server:start --no-tls`
6. Start the scheduler worker:
   `php bin/console messenger:consume scheduler_default -vv`

## Tests

The suite uses a separate `_test` database (Doctrine appends the suffix to the
database in `DATABASE_URL`). Put local test credentials in `.env.test.local`
or `.env.local`, then run:

```bash
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
```

## Environment and secrets

- Commit `.env` (safe defaults only) and `.env.docker.example`; never commit
  `.env.local`, `.env.*.local` or the JWT keys.
- Do not place real secrets in `compose.yaml`, the `Dockerfile` or any committed
  `.env` file.
- Inside Docker the application reaches the database and hub by their service
  names (`mariadb`, `mercure`); `localhost` URLs are only for the browser and
  host-side tools.
