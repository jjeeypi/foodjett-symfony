# Foodjett

Foodjett is a food-delivery platform built with Symfony 7.4. It has four roles:
customers order from restaurants, restaurants manage menus and orders, riders
accept and deliver orders, and administrators approve partners and run the
platform. Order tracking, chat and the rider order pool update live through a
Mercure hub.

The Symfony application lives at the repository root and renders every page
with Twig. Signing in is a session login with an email address or phone number.
Each role has its own area:

| Area | Path | Who |
| --- | --- | --- |
| Browsing, cart, checkout, orders, account | `/restaurants`, `/cart`, `/checkout`, `/orders`, `/account` | Customers |
| Dashboard, orders, menu, hours, documents, vouchers, earnings | `/restaurant` | Restaurants |
| Dashboard, order pool, deliveries, documents, cash and pay | `/rider` | Riders |
| Approvals, customers, orders, refunds, payouts, settings, audit log | `/admin` | Administrators |
| Chats and help requests | `/messages`, `/support` | Everyone signed in |

Pages that show changing data (order tracking, the restaurant order board, the
rider pool, chats) subscribe to their own Mercure topics and refresh in place
when the server signals a change.

### Design

The interface follows two Figma Make mockups: one for the customer and rider
apps, one for the restaurant and admin back office. The styles are plain CSS in
`assets/styles/app.css` (AssetMapper, no build step), and the icons are one SVG
sprite in `assets/images/icons.svg`. Every page uses the same layout: a sidebar
whose links depend on the role, which can be collapsed to icons and becomes a
bottom bar on phones, and a top bar with the delivery address and cart, the
rider's online switch, or the restaurant's open status.

What each role sees:

- **Customers:** an explore page with a craving row and restaurant cards,
  favorites, restaurant pages with a cover photo and the menu, cart, checkout,
  live order tracking, addresses, chat and support.
- **Restaurants:** a dashboard, an order board with one column per kitchen
  stage, a menu manager with an on/off switch per dish, options and add-ons,
  hours, vouchers, reviews with replies, earnings and payouts, documents and
  the profile.
- **Riders:** the order pool with offer cards, status and location sharing, the
  active delivery with step-by-step actions and directions, earnings, cash
  remittances, the account and documents.
- **Administrators:** a dashboard, restaurant and rider applications with
  document checks, orders with rider assignment and cancellation, customers,
  payments and refunds, payouts, remittances, reports, reviews, support,
  vouchers, delivery zones, settings, the audit log and admin accounts.

Prices are shown in pesos (₱). A restaurant that has not uploaded a cover photo
gets a drawn cover with its food icon.

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

3. Optionally set `SEED_CATALOG_PASSWORD` in `.env.docker.local` to load the
   demo catalog on the first start: 50 Philippine restaurant chains with their
   menus, approved and open, at made-up Dumaguete addresses inside a 10 km
   delivery zone. Each owner signs in as `<slug>@restaurants.foodjett.test`
   (for example `jollibee@restaurants.foodjett.test`) with that password.
   Later starts skip the restaurants that already exist, so they do no extra
   work. Leave it empty for an empty platform.

4. Build and start the four services:

   ```bash
   docker compose up -d --build
   docker compose ps
   ```

5. Create the first administrator:

   ```bash
   docker compose exec app php bin/console app:make-admin "Foodjett Admin" admin@example.test "<choose-a-password>"
   ```

6. Open the application at <http://localhost:8080>. The health check is
   <http://localhost:8080/api/health>.

On every start the `app` container runs the Doctrine migrations, then loads the
catalog when `SEED_CATALOG_PASSWORD` is set, before Apache starts. The services
use `restart: unless-stopped`, so they come back on their own whenever Docker
Desktop starts; after the first setup, opening Docker is enough.

The catalog can also be loaded by hand, for example into a running stack:

```bash
docker compose exec app php bin/console app:seed-catalog --password="<choose-a-password>"
```

The menus come from `data/catalog.json`. Prices are approximate, and the
descriptions that the source lacked were written for this project. The dish
and cover pictures in `public/images/catalog` are AI-generated illustrations
(Stable Diffusion XL), not photos of the real dishes.

### Services

| Service | Purpose | Host exposure |
| --- | --- | --- |
| `mariadb` | MariaDB 10.4.32 application database | Internal only |
| `mercure` | Mercure 1.1.0 Server-Sent Events hub | `localhost:3000` |
| `app` | Symfony under Apache with PHP 8.4 | `localhost:8080` |
| `scheduler` | Messenger worker that runs the rider-pool escalation schedule | Internal only |

Apache reverse-proxies `/.well-known/mercure` to the hub, so the browser reaches
the application and Mercure on the same origin
(`http://localhost:8080/.well-known/mercure`). Symfony publishes to the hub
internally at `http://mercure/.well-known/mercure`.

MariaDB data, Mercure state and uploaded files use named volumes, so stopping
and restarting keeps data. Menu photos are public (`public/uploads`); restaurant
and rider documents are private (`var/uploads`) and only reachable through the
owner's and the admins' pages. To use other host ports, set
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

Native development uses PHP 8.4 or newer with the `bcmath`, `intl`,
`pdo_mysql` and `zip` extensions, Composer and a local MariaDB.

On Windows, develop inside WSL (Ubuntu) and keep the project in the Linux file
system (for example `~/foodjett`), not under `/mnt/c`. Pages and the test suite
run several times faster there. Ubuntu's packages cover everything:

```bash
sudo apt install php-cli php-bcmath php-curl php-intl php-mbstring php-mysql php-xml php-zip composer mariadb-server
```

1. Install dependencies: `composer install`
2. Create `.env.local` with your local `DATABASE_URL` (and `APP_SECRET`). It is
   gitignored. Set `serverVersion` in the URL to your MariaDB version.
3. Create the schema: `php bin/console doctrine:migrations:migrate`
4. Start the Mercure hub only: `docker compose up -d mercure` (listens on
   `http://localhost:3000/.well-known/mercure`). Without it the site still
   works; pages just do not refresh live.
5. Start the web server: `php -S 0.0.0.0:8000 -t public` (or
   `symfony server:start --no-tls`), then open <http://localhost:8000>. From
   WSL the Windows browser reaches it at the same address.
6. Start the scheduler worker:
   `php bin/console messenger:consume scheduler_default -vv`
7. Optionally load the demo catalog:
   `php bin/console app:seed-catalog --password="<choose-a-password>"`

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
  `.env.local` or `.env.*.local`.
- Do not place real secrets in `compose.yaml`, the `Dockerfile` or any committed
  `.env` file.
- Inside Docker the application reaches the database and hub by their service
  names (`mariadb`, `mercure`); `localhost` URLs are only for the browser and
  host-side tools.

## Money

Every amount is stored as `DECIMAL(10,2)` and handled in PHP as an exact
decimal string with `bcmath` (`bcadd`, `bcsub`, `bcmul`, `bcdiv`, `bccomp`,
and `bcround` for half-up rounding). Floats are used only for measurements
such as coordinates, distances and ratings.
