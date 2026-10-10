# Foodjett Symfony API

Foodjett's backend is a Symfony JSON API. The React/Vite application in `../web` is a separate SPA.

## Required local processes

Run these processes during development:

1. MariaDB from XAMPP.
2. The Symfony API server, for example `symfony server:start --no-tls` from `api/`.
3. The React/Vite development server from `web/`.
4. The rider-pool scheduler consumer: `php bin/console messenger:consume scheduler_rider_pool_escalation -vv`.
5. The Mercure hub: `docker compose up -d mercure` from `api/`.

The local Mercure hub listens at `http://localhost:3000/.well-known/mercure`. Browser subscribers must create `EventSource` with `{ withCredentials: true }` after calling `POST /api/mercure-auth`, which installs an HttpOnly subscriber cookie scoped to the topics the authenticated user owns.

The checked-in hub secret is development-only. Set matching, strong `MERCURE_JWT_SECRET`, `MERCURE_PUBLISHER_JWT_KEY`, and `MERCURE_SUBSCRIBER_JWT_KEY` values outside source control in production.
