# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-11-16

- Sync several channels to their own Pricee websites: choose the channel on the sync page, and set each channel's API key and webhook secret under `pricee_io_sync.channels` (see the README)
- Product URLs sent to Pricee use the channel's domain and default language, and only products sold on that channel are synced
- Webhook price updates only change the channel whose secret signed the call
- A refused webhook call no longer prevents Pricee from retrying it
- Sync errors now show their message on the sync page
- Requires Sylius 2.1 or later and PHP 8.2 or later: Sylius 1.14 is no longer supported

## [1.5.0] - 2026-11-15

- Products are synced to the Pricee website your API key belongs to: create the API key from the Integrations page of your shop's website on Pricee
- The sync stops with a clear message when the API key belongs to another website, or is not tied to a website
- The plugin no longer creates websites on Pricee

## [1.4.0] - 2026-11-15

- Report to Pricee the outcome of each price update, so you can see in Pricee which prices were applied or skipped and why

## [1.3.0] - 2026-11-15

- Follow the updated format of Pricee's webhook calls, required for automatic price updates
- Never overwrite a price you changed yourself: a webhook update is skipped when the product's price changed since Pricee last read it
- Only update the price of products with a single variant, on the channel the product page belongs to, instead of giving every variant and every channel in that currency the same price
- Skip webhook price updates for disabled products, prices with a catalog promotion or a shown original price, and prices below the channel's minimum price
- Match webhook updates to the product synced with its ID instead of its URL, and skip a URL shared by several products
- Sync large catalogs without timeouts: products are sent in batches with a progress indicator and the number of products in error, and the 500-product limit is removed

## [1.2.2] - 2026-10-30

- Paginate the Pricee websites API during sync so the shop website is found on accounts with many websites instead of creating a duplicate

## [1.2.1] - 2026-10-30

- Fix PHPStan level max errors: typed taxon repository, EntityManager-based queries in the sync controller, string validation of credentials
- Simplify CI: fast lint/PHPStan/unit-test workflow plus a reduced Sylius compatibility matrix (2.1/2.2 x Symfony 6.4/7.4), Dependabot config aligned with the other integrations

## [1.2.0] - 2026-10-30

- Update product URLs on Pricee during sync when they changed and adopt products synced before external IDs existed, without creating duplicates

## [1.1.0] - 2026-10-27

- Add webhook receiver: apply Pricee.io price updates to Sylius channel pricings (HMAC-SHA256 signature over the raw body, timestamp/nonce replay protection, price validation, per-channel currency matching)
- Handle batch payloads sharing a single nonce and skip entries and channels with unknown currency
- Add AGENTS.md guidance for AI coding agents
- Add PHP and Sylius prerequisites to the README

## [1.0.4] - 2026-01-22

- Handle possible errors when creating a product in Pricee.io

## [1.0.3] - 2026-01-22

- More efficient iteration over products during sync

## [1.0.2] - 2026-01-22

- Use env vars to get API key credentials (for persistence and better security)
- Small translations updates

## [1.0.1] - 2026-01-21

- Fix URL of the website sent to Pricee.io

## [1.0] - 2026-01-07

- Initial version: admin sync UI, Pricee.io API client, category-based product synchronization
