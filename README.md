# Pricee.io | Sylius Integration

![Packagist Version](https://img.shields.io/packagist/v/priceeio/integration-sylius)

**Pricee.io Sylius Plugin** allows you to sync your Sylius products to the [Pricee.io](https://pricee.io) platform for price monitoring.

## Features

- Authenticate with Pricee.io using Client ID & Secret Key (API Key)
- Sync selected product categories from Sylius to Pricee.io

## Prerequisites

- PHP 8.2 or higher
- Sylius 2.1 or higher

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

3. Import the plugin routes in your application `config/routes.yaml` (the sync page and the price webhook):

```yaml
priceeio_sync:
    resource: "@PriceeIOSyncPlugin/config/routes.yaml"
```

The sync page is served under your admin path (`SYLIUS_ADMIN_ROUTING_PATH_NAME`), behind the admin login.

4. Clear cache:

```bash
php bin/console cache:clear
```

5. Configure your Pricee.io API key credentials with environment variables. Create the API key from the **Integrations** page of this shop's website on Pricee.io: each key is tied to one website.

```bash
PRICEEIO_CLIENT_ID=your_client_id
PRICEEIO_API_KEY=your_api_key
```

### Several channels

Each channel is one website on Pricee.io, with its own API key and webhook secret. Set them per channel code in `config/packages/pricee_io_sync.yaml`; a channel left out, or a value left empty, uses the `PRICEEIO_*` environment variables above, so a shop with a single channel needs nothing more.

```yaml
pricee_io_sync:
    channels:
        FASHION_FR:
            client_id: '%env(PRICEEIO_FR_CLIENT_ID)%'
            api_key: '%env(PRICEEIO_FR_API_KEY)%'
            webhook_secret: '%env(PRICEEIO_FR_WEBHOOK_SECRET)%'
        FASHION_DE:
            client_id: '%env(PRICEEIO_DE_CLIENT_ID)%'
            api_key: '%env(PRICEEIO_DE_API_KEY)%'
            webhook_secret: '%env(PRICEEIO_DE_WEBHOOK_SECRET)%'
```

## Usage

1. Go to **Admin → Pricee.io → Synchronisation**.
2. Choose the channel to sync, when the shop has several.
3. Select product categories you want to sync.
4. Click **Synchronise** to send products to Pricee.io.

The plugin will automatically:

* Sync to the Pricee.io website the channel's API key is tied to (the sync stops with an error if it is not this channel's website)
* Sync the enabled products of the selected categories sold on the channel, with their page on the channel's hostname and in its default language
* Return a success message with the number of synced products

## Webhook (automatic price updates)

The plugin exposes a webhook endpoint that receives the prices Pricee.io computes for your products and applies them to their channel pricing. A price is only written for a product with a single variant, on the channel of its page, when it has no catalog promotion, stays above the channel's minimum price, and was not changed in the shop since Pricee.io last read it.

1. Enable the webhook with environment variables (its route comes with the plugin routes imported at installation):

```bash
PRICEEIO_WEBHOOK_ENABLED=1
PRICEEIO_WEBHOOK_SECRET=your_webhook_secret
```

2. In Pricee.io, configure the webhook URL to:

```
https://your-shop.example.com/priceeio/webhook
```

With several channels, give each one the webhook secret of its Pricee.io website (`webhook_secret` above): a call only updates the channel whose secret signed it, and `PRICEEIO_WEBHOOK_SECRET` the channels without their own one.

Each request is signed with HMAC-SHA256 over the raw body (`X-Signature` header) using the webhook secret, and carries a timestamp and a nonce so stale or replayed payloads are rejected. Received prices are validated before being written; channel pricings are updated for channels whose base currency matches the price currency.

## Support

For issues, contact [hello@pricee.io](mailto:hello@pricee.io) or open an issue in this repository.
