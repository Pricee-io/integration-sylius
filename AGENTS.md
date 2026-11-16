# AGENTS.md

Guidance for AI coding agents working in this repository.

## Project

Pricee.io Sylius plugin (`src/`, namespace `PriceeIO\SyncPlugin`): syncs Sylius products to the Pricee.io platform. PHP ^8.2, Sylius ^2.1 (see `composer.json`).

- `src/Controller/Admin/SyncController.php` — admin sync page (GET) and AJAX sync endpoint (POST, CSRF-protected)
- `src/Service/SyncService.php` — sync logic; `src/Service/ApiService.php` — Pricee.io API client
- `src/Helper/Params.php` — reads credentials/config from environment variables
- `config/routes.yaml` — every route, imported by shops (`config/routes/admin.yaml` under `/%sylius_admin.path_name%`, so behind the admin firewall; `config/routes/webhook.yaml`); `config/services.yaml` — DI config
- `templates/admin/sync/` — Twig admin templates

## Development

Tooling runs through Docker Compose (see `Makefile`):

- `make init` — first-time setup (composer install, assets, containers)
- `make php-shell` / `make node-shell` — shells into the dev containers
- `make lint` — EasyCodingStandard (`ecs check src --fix`)
- `make phpunit` — test suite (requires the test app: `composer test-app-init` then `composer database-reset`)

Static analysis: `vendor/bin/phpstan` (config in `phpstan.neon`, level max). CI runs a fast check (lint, unit tests) on every PR and push to main (`.github/workflows/ci.yml`), plus the full build against a Sylius matrix (`.github/workflows/sylius-compat.yml`).

Run lint, phpstan and phpunit before committing.

## Commits

- Do NOT add `Co-Authored-By` trailers or any AI attribution to commit messages.

## Versioning

The plugin has no version constant: releases are git tags (`1.0` … `1.0.4`) picked up by Packagist. Each release must add a `## [x.y.z] - YYYY-MM-DD` entry at the top of `CHANGELOG.md`. Keep `composer.json` constraints (`php`, `sylius/sylius`) in sync with the README prerequisites.

Changelog rules (this is a Sylius plugin, so the changelog is read by merchants, not developers):

- Only user-facing changes: what the plugin user sees or experiences in the admin or the storefront. Skip CI, tooling, Dependabot, static analysis and internal refactors.
- One short line per change, no implementation details (no function names, no file paths, no "fix X by doing Y" narration).

## Webhook contract

The price-update webhook is received by `src/Controller/WebhookController.php` (route `POST /priceeio/webhook`, `config/routes/webhook.yaml`). The payload is a JSON array of entries signed with HMAC-SHA256 over the **raw request body** using the webhook secret (`X-Signature` header). Each entry carries `url`, `extId` (optional: the shop's internal product ID, null for products synced before external IDs existed), `priceAmount` (the price to set), `priceCurrency`, `currentPriceAmount` (the price Pricee last read on the page), `timestamp` and `nonce`; the controller rejects entries with a missing/stale timestamp (> `WebhookController::MAX_AGE`) or an already-used nonce (stored in the `cache.app` pool for replay protection). Each channel is one Pricee website: its credentials and webhook secret come from `pricee_io_sync.channels` (by channel code), else the `PRICEEIO_*` env vars (`src/Helper/Params.php`), and a call only updates the channel whose own secret signed it (the shared secret: the channels without one). A sync targets exactly one channel chosen on the page, with product URLs on its hostname and default locale. Prices are applied by `src/Service/WebhookPriceUpdater.php` to the `ChannelPricing` (minor units) of the product's one allowed channel in that currency (the URL's hostname decides between several), resolving products by `extId` (never falling back to the URL for an unknown one), else by a URL slug only one product has. Writing a price is the critical action: when in doubt, skip the entry and log why. Products with several variants, disabled ones, prices with a catalog promotion or a shown original price, prices changed since Pricee read them (`currentPriceAmount`) and prices below the channel's minimum price are skipped. The answer gives `outcomes` (entries per outcome) and `results` (the outcome of each entry, in payload order). Every answer, refused calls included, carries `plugin` (`{"platform": "sylius", "version": <installed version from Composer, null when unknown>}`), which the app stores in its webhook history; an accepted call's also gives `platform_version` (`SyliusCoreBundle::VERSION`) and `runtime` (`php`) with `runtime_version`, kept from refused calls as anyone can read their answer.

Any change to this format is a cross-repo contract: the sender lives in `../app/src/Service/WebhookService.php` and the WooCommerce/PrestaShop integrations validate the same signature — keep changes backward compatible or coordinate all of them.

## End-to-end tests

`make e2e` (`e2e/run.sh`) starts its own throwaway database and PHP containers (never the dev ones) serving the test application on the working tree, with two channels (`E2E_PRO` has its own webhook secret) and an admin from the `pricee_e2e` fixture suite (`e2e/config.yaml`). It tests real signed HTTP webhook calls, the sync page behind the admin login, and a sync from the admin in a browser (Playwright, `e2e/sync.mjs`) against a fake Pricee API (`e2e/fake-api.php`, reached through `PRICEEIO_API_BASE_URL`). It needs `vendor/` and the test application's built assets (`composer frontend-clear`, from the dependency tree locked in `tests/TestApplication/yarn.lock`: without it yarn takes a webpack the Stimulus bridge does not work with); `.github/workflows/e2e.yml` builds them with SyliusLabs/BuildTestAppAction for each supported Sylius version. The fake API mirrors the app's API for the calls the plugin makes: keep it in line with `../app` and with the PrestaShop and WooCommerce ones.
