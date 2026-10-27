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

The plugin has no version constant: releases are git tags (`1.0` … `1.0.4`) picked up by Packagist. Each release must add a `## [x.y.z] - YYYY-MM-DD` entry at the top of `CHANGELOG.md`. Keep `composer.json` constraints (`php`, `sylius/sylius`) in sync with the README prerequisites.

## Webhook contract

The price-update webhook is received by `src/Controller/WebhookController.php` (route `POST /priceeio/webhook`, `config/routes/webhook.yaml`). The payload is a JSON array of entries signed with HMAC-SHA256 over the **raw request body** using the webhook secret (`X-Signature` header). Each entry carries `url`, `bestPriceAmount`, `bestPriceCurrency`, `timestamp` and `nonce`; the controller rejects entries with a missing/stale timestamp (> `WebhookController::MAX_AGE`) or an already-used nonce (stored in the `cache.app` pool for replay protection). Prices are applied by `src/Service/WebhookPriceUpdater.php` to `ChannelPricing` (minor units) for channels whose base currency matches, resolving products by URL slug.

Any change to this format is a cross-repo contract: the sender lives in `../app/src/Service/WebhookService.php` and the WooCommerce/PrestaShop integrations validate the same signature — keep changes backward compatible or coordinate all of them.
