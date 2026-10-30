# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
