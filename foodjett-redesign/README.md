# Foodjett customer redesign

Standalone React + Vite + Tailwind CSS customer demo exported from Figma Make.
Copy this entire folder into your Symfony project root without replacing the
existing Symfony files.

## Run locally

Use Node.js 22.12 or newer and pnpm 10.

```sh
cd foodjett-redesign
pnpm install --frozen-lockfile
pnpm dev
```

Open the local URL printed by Vite.

## Production build

```sh
pnpm build
pnpm preview
```

The generated static website is in `dist/`. Merely copying this source folder
into Symfony does not create a Symfony route or connect it to the backend.
Deploy `dist/` separately or integrate it with Symfony in a subsequent step.

## Scope and limitations

- Responsive customer UI with restaurant search and filtering, favorites,
  demo cart and checkout, tracking, address editing, and an account form.
- Mock data and in-memory state: changes reset on reload.
- No real authentication, payment processing, order placement, or backend API.
- Restaurant, rider, admin, and authentication redesigns are not included.
- Restaurant photography and web fonts use external services and require
  internet access.
- Figma-specific tooling, Git history, credentials, installed dependencies,
  and build output are intentionally excluded.
