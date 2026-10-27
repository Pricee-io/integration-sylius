# Pricee.io | Sylius Integration

![Packagist Version](https://img.shields.io/packagist/v/priceeio/integration-sylius)

**Pricee.io Sylius Plugin** allows you to sync your Sylius products to the [Pricee.io](https://pricee.io) platform for price monitoring.

## Features

- Authenticate with Pricee.io using Client ID & Secret Key (API Key)
- Sync selected product categories from Sylius to Pricee.io

## Prerequisites

- PHP 8.1 or higher
- Sylius 1.14 or higher

## Installation

1. Require the plugin via Composer:

```bash
composer require priceeio/integration-sylius
````

2. Enable the bundle in `config/bundles.php`:

```php
return [
    // ...
    PriceeIO\SyncPlugin\PriceeIOSyncPlugin::class => ['all' => true],
];
```

3. Clear cache:

```bash
php bin/console cache:clear
```

4. Configure your Pricee.io API key credentials with environment variables:

```bash
PRICEEIO_CLIENT_ID=your_client_id
PRICEEIO_API_KEY=your_api_key
```

## Usage

1. Go to **Admin → Pricee.io → Synchronisation**.
2. Select product categories you want to sync.
3. Click **Synchronise** to send products to Pricee.io.

The plugin will automatically:

* Fetch or create the website in Pricee.io
* Sync products from selected categories
* Return a success message with the number of synced products

## Webhook (automatic price updates)

The plugin exposes a webhook endpoint that receives competitor price updates from Pricee.io and applies them to your channel pricings.

1. Import the webhook route in your application `config/routes.yaml`:

```yaml
priceeio_sync_webhook:
    resource: "@PriceeIOSyncPlugin/config/routes/webhook.yaml"
```

2. Enable the webhook with environment variables:

```bash
PRICEEIO_WEBHOOK_ENABLED=1
PRICEEIO_WEBHOOK_SECRET=your_webhook_secret
```

3. In Pricee.io, configure the webhook URL to:

```
https://your-shop.example.com/priceeio/webhook
```

Each request is signed with HMAC-SHA256 over the raw body (`X-Signature` header) using the shared secret, and carries a timestamp and a nonce so stale or replayed payloads are rejected. Received prices are validated before being written; channel pricings are updated for channels whose base currency matches the price currency.

## Support

For issues, contact [hello@pricee.io](mailto:hello@pricee.io) or open an issue in this repository.
