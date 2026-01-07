# Pricee.io | Sylius Integration

![Packagist Version](https://img.shields.io/packagist/v/priceeio/integration-sylius%20)

**Pricee.io Sylius Plugin** allows you to sync your Sylius products to the [Pricee.io](https://pricee.io) platform for price monitoring.

## Features

- Authenticate with Pricee.io using Client ID & Secret Key (API Key)
- Sync selected product categories from Sylius to Pricee.io

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

## Usage

1. Go to **Admin → Pricee.io → Synchronisation**.
2. Enter your **Client ID** and **Secret Key**.
3. Select product categories you want to sync.
4. Click **Synchronise** to send products to Pricee.io.

The plugin will automatically:

* Fetch or create the website in Pricee.io
* Sync products from selected categories
* Return a success message with the number of synced products

## Support

For issues, contact [hello@pricee.io](mailto:hello@pricee.io) or open an issue in this repository.
