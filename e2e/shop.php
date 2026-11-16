<?php

/*
 * End-to-end test helper, run inside the throwaway shop container by
 * e2e/run.sh (never against a dev shop: it writes to the shop database).
 *
 *   php e2e/shop.php webhook          real signed HTTP calls to the webhook, prices checked in DB
 *   php e2e/shop.php sync-fixtures    category with enough products for several sync batches
 *   php e2e/shop.php check-sync       what the fake Pricee API received from the sync
 */

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\TestApplication\Kernel;

const SECRET = 'e2e-webhook-secret';
const PRO_SECRET = 'e2e-pro-webhook-secret';
const FIXTURES_FILE = '/tmp/pricee-e2e-fixtures.json';
const SYNC_PRODUCTS = 55; // more than one batch of 50
const FAKE_API_STATE = 'http://127.0.0.1:8090/_state';
const SHOP_ADDRESS = 'http://127.0.0.1:8000';

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/vendor/sylius/test-application/config/bootstrap.php';

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function pass(string $message): void
{
    echo "ok - {$message}\n";
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message.': expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
    pass($message);
}

function em(): EntityManagerInterface
{
    global $container;

    return $container->get('doctrine.orm.entity_manager');
}

function channel(string $code): ChannelInterface
{
    global $container;

    $channel = $container->get('sylius.repository.channel')->findOneByCode($code);
    if (!$channel instanceof ChannelInterface) {
        fail('missing channel '.$code);
    }

    return $channel;
}

/**
 * A single-variant product sold on the given channels at 10.00, no promotion.
 *
 * @param array<int, string> $channels
 */
function createProduct(string $name, array $channels, string $taxon, bool $enabled = true): ProductInterface
{
    global $container;

    /** @var ProductInterface $product */
    $product = $container->get('sylius.fixture.example_factory.product')->create([
        'name' => $name,
        'main_taxon' => $taxon,
        'taxons' => [$taxon],
        'channels' => $channels,
        'enabled' => $enabled,
        'images' => [],
        'product_attributes' => [],
        'product_options' => [],
    ]);
    foreach ($product->getVariants() as $variant) {
        foreach ($variant->getChannelPricings() as $pricing) {
            $pricing->setPrice(1000);
            $pricing->setOriginalPrice(null);
            $pricing->setMinimumPrice(0);
        }
    }
    em()->persist($product);
    em()->flush();

    return $product;
}

function storedPrice(ProductInterface $product, string $channelCode): ?int
{
    em()->clear();
    $product = em()->find(ProductInterface::class, $product->getId());
    $variant = $product?->getVariants()->first();

    return $variant instanceof ProductVariantInterface ? $variant->getChannelPricingForChannel(channel($channelCode))?->getPrice() : null;
}

/**
 * @return array{0: int, 1: array<string, mixed>, 2: string} status, decoded answer and the request body sent
 */
function sendWebhook(array $entries, string $secret = SECRET, ?string $body = null): array
{
    $body ??= (string) json_encode($entries);
    $curl = curl_init(SHOP_ADDRESS.'/priceeio/webhook');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Host: localhost', 'Content-Type: application/json', 'X-Signature: '.hash_hmac('sha256', $body, $secret), 'User-Agent: Pricee'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $answer = json_decode($response, true);

    return [$status, is_array($answer) ? $answer : ['raw' => substr($response, 0, 300)], $body];
}

/**
 * @return array<string, mixed>
 */
function entry(ProductInterface $product, float $price, float $readPrice = 10.0, string $host = 'localhost', ?string $nonce = null): array
{
    return [
        'url' => 'http://'.$host.'/en_US/products/'.$product->getSlug(),
        'extId' => (string) $product->getId(),
        'priceAmount' => $price,
        'priceCurrency' => 'EUR',
        'currentPriceAmount' => $readPrice,
        'timestamp' => time(),
        'nonce' => $nonce ?? bin2hex(random_bytes(16)),
    ];
}

function webhook(): void
{
    $web = createProduct('Pricee E2E web', ['E2E_WEB'], 'pricee_e2e_webhook');
    $both = createProduct('Pricee E2E both channels', ['E2E_WEB', 'E2E_PRO'], 'pricee_e2e_webhook');
    $pro = createProduct('Pricee E2E pro', ['E2E_PRO'], 'pricee_e2e_webhook');

    [$status, $answer, $body] = sendWebhook([entry($web, 8.0), entry($pro, 7.0, 10.0, 'pro.localhost')]);
    assertSame(200, $status, 'call signed with the shared secret accepted ('.json_encode($answer).')');
    assertSame(['updated_price', 'skipped_channel'], $answer['results'] ?? null, 'shared secret updates the channel without its own secret only');
    assertSame([800, 1000], [storedPrice($web, 'E2E_WEB'), storedPrice($pro, 'E2E_PRO')], 'prices in the database');

    // Signed with E2E_PRO's own secret: only E2E_PRO is written, whatever the URL's host
    [$status, $answer] = sendWebhook([entry($both, 9.0)], PRO_SECRET);
    assertSame([200, ['updated_price']], [$status, $answer['results'] ?? null], 'call signed with a channel secret accepted');
    assertSame([1000, 900], [storedPrice($both, 'E2E_WEB'), storedPrice($both, 'E2E_PRO')], 'only the channel of the secret is updated');

    [$status] = sendWebhook([], SECRET, $body);
    assertSame(401, $status, 'replayed call refused');

    [$status] = sendWebhook([entry($web, 5.0, 8.0)], 'wrong-secret');
    assertSame(401, $status, 'call with a wrong signature refused');
    assertSame(800, storedPrice($web, 'E2E_WEB'), 'refused calls write nothing');

    // A call refused for one stale entry does not use up its nonce: once fixed, it gets through
    $nonce = bin2hex(random_bytes(16));
    $stale = entry($web, 7.5, 8.0, 'localhost', $nonce);
    $stale['timestamp'] = time() - 3600;
    [$status] = sendWebhook([entry($web, 7.5, 8.0, 'localhost', $nonce), $stale]);
    assertSame(401, $status, 'call with a stale entry refused');
    [$status, $answer] = sendWebhook([entry($web, 7.5, 8.0, 'localhost', $nonce)]);
    assertSame([200, ['updated_price']], [$status, $answer['results'] ?? null], 'its retry with the same nonce accepted');

    // The retry of a call applied but answered too late: Pricee still sends the price it read before
    [$status, $answer] = sendWebhook([entry($web, 7.5, 8.0)]);
    assertSame([200, ['unchanged']], [$status, $answer['results'] ?? null], 'retried call already applied answered unchanged');

    // The merchant changed the price since Pricee read it: their change wins
    [$status, $answer] = sendWebhook([entry($web, 6.0, 9.0)]);
    assertSame([200, ['skipped_price_changed_in_shop']], [$status, $answer['results'] ?? null], 'price changed in the shop skipped');
    assertSame(750, storedPrice($web, 'E2E_WEB'), 'price kept');
}

function syncFixtures(): void
{
    $ids = [];
    for ($i = 1; $i <= SYNC_PRODUCTS; ++$i) {
        $ids[] = (int) createProduct('Pricee E2E sync '.$i, ['E2E_WEB'], 'pricee_e2e')->getId();
    }
    // Neither synced nor counted on E2E_WEB: a disabled product, one sold on E2E_PRO only
    createProduct('Pricee E2E disabled', ['E2E_WEB'], 'pricee_e2e', false);
    createProduct('Pricee E2E pro only', ['E2E_PRO'], 'pricee_e2e');

    file_put_contents(FIXTURES_FILE, json_encode(['products' => $ids]));
    pass(SYNC_PRODUCTS.' products created in category Pricee E2E');
}

function checkSync(): void
{
    $fixtures = json_decode((string) file_get_contents(FIXTURES_FILE), true);
    $state = json_decode((string) file_get_contents(FAKE_API_STATE), true);
    if (!is_array($fixtures) || !is_array($state)) {
        fail('missing fixtures or fake API state');
    }

    assertSame([], $state['websites'], 'no website created on Pricee');
    assertSame(['/api/v1/websites/11111111-0000-4000-8000-000000000001'], array_values(array_unique(array_column($state['products'], 'website'))), 'products sent to the website of the API key');

    $extIds = array_map('intval', array_column($state['products'], 'extId'));
    sort($extIds);
    assertSame($fixtures['products'], $extIds, 'every enabled product of the category on the channel sent once, with its ID');

    foreach ($state['products'] as $sent) {
        $product = em()->find(ProductInterface::class, (int) $sent['extId']);
        $expected = 'http://localhost/en_US/products/'.$product?->getTranslation('en_US')->getSlug();
        if ($sent['url'] !== $expected) {
            fail('product '.$sent['extId'].' sent with URL '.$sent['url'].' instead of '.$expected);
        }
    }
    pass('product URLs are the product pages on the channel domain, in its language');

    $routes = array_column($state['requests'], 'route');
    // Each batch checks the key's website
    $batches = count(array_filter($routes, static fn (string $route): bool => str_starts_with($route, 'websites/')));
    assertSame((int) ceil(SYNC_PRODUCTS / 50), $batches, 'sync sent in batches of 50');
    // Pricee allows few logins per minute: the batches reuse the first one's token
    assertSame(1, count(array_keys($routes, 'login', true)), 'one login for the whole sync');
}

match ($argv[1] ?? '') {
    'webhook' => webhook(),
    'sync-fixtures' => syncFixtures(),
    'check-sync' => checkSync(),
    default => fail('unknown command '.($argv[1] ?? '')),
};
