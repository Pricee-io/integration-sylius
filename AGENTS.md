# AGENTS.md

Guidance for AI coding agents working in this repository.

## Project

Pricee.io Sylius plugin (`src/`, namespace `PriceeIO\SyncPlugin`): syncs Sylius products to the Pricee.io platform. PHP ^8.1, Sylius ^1.14 || ^2.x (see `composer.json`).

- `src/Controller/Admin/SyncController.php` — admin sync page (GET) and AJAX sync endpoint (POST, CSRF-protected)
- `src/Service/SyncService.php` — sync logic; `src/Service/ApiService.php` — Pricee.io API client
- `src/Helper/Params.php` — reads credentials/config from environment variables
- `config/routes/admin.yaml` — routes; `config/services/` — DI config
- `templates/admin/sync/` — Twig admin templates

## Development

Tooling runs through Docker Compose (see `Makefile`):

- `make init` — first-time setup (composer install, assets, containers)
- `make php-shell` / `make node-shell` — shells into the dev containers
- `make lint` — EasyCodingStandard (`ecs check src --fix`)
- `make phpunit` — test suite (requires the test app: `composer test-app-init` then `composer database-reset`)

Static analysis: `vendor/bin/phpstan` (config in `phpstan.neon`, level max). CI runs the full build against a Sylius matrix (`.github/workflows/build.yaml`).

Run lint, phpstan and phpunit before committing.

## Commits

- Do NOT add `Co-Authored-By` trailers or any AI attribution to commit messages.

## Versioning

The plugin has no version constant: releases are git tags (`1.0` … `1.0.4`) picked up by Packagist. Keep `composer.json` constraints (`php`, `sylius/sylius`) in sync with the README prerequisites.

## Known gap

Unlike the WooCommerce and PrestaShop integrations, this plugin has **no webhook receiver**: price updates pushed by the app (`../app/src/Service/WebhookService.php`, HMAC-SHA256 over the raw body via `X-Signature`, entries carrying `timestamp`/`nonce` for replay protection) cannot be applied to Sylius yet. Any receiver added here must validate signature, timestamp freshness (±5 min) and nonce uniqueness, resolve products by URL slug, and update `ChannelPricing` per channel/currency.
