<?php

declare(strict_types=1);

namespace Tests\PriceeIO\SyncPlugin\Unit\Service;

use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use PriceeIO\SyncPlugin\Helper\Params;
use PriceeIO\SyncPlugin\Service\ApiService;
use PriceeIO\SyncPlugin\Service\SyncService;
use Psr\Log\NullLogger;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Product\Model\ProductTranslationInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * The website a sync targets: always the one the API key is scoped to,
 * which must be this shop's website.
 */
final class SyncServiceTest extends TestCase
{
    private const WEBSITE_ID = '/api/v1/websites/11111111-1111-4111-8111-111111111111';

    public function testSyncsToTheWebsiteOfTheApiKey(): void
    {
        // Registered on Pricee with www., which Pricee ignores when matching products
        $service = $this->createService([['@id' => self::WEBSITE_ID, 'url' => 'https://www.shop.example']]);

        $login = ['token' => 'token', 'website' => self::WEBSITE_ID];

        $this->assertSame(self::WEBSITE_ID, $service->resolveWebsiteId($login, 'https://shop.example/'));
    }

    public function testApiKeyOfAnotherWebsiteStopsTheSync(): void
    {
        $service = $this->createService([['@id' => self::WEBSITE_ID, 'url' => 'https://another-shop.example']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('https://another-shop.example');

        $service->resolveWebsiteId(['token' => 'token', 'website' => self::WEBSITE_ID], 'https://shop.example');
    }

    public function testApiKeyWithoutWebsiteStopsTheSync(): void
    {
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not tied to a website');

        $service->resolveWebsiteId(['token' => 'token', 'website' => null], 'https://shop.example');
    }

    public function testSyncsProductPagesOnTheChannelDomainInItsDefaultLanguage(): void
    {
        $created = [];
        $service = $this->createService(
            [['@id' => self::WEBSITE_ID, 'url' => 'https://shop.example.fr']],
            $created,
            ['WEB_FR' => ['client_id' => 'fr_client', 'api_key' => 'fr_key']],
        );

        $channel = $this->createChannel('WEB_FR', 'shop.example.fr', 'fr_FR');
        $count = $service->syncProducts($channel, SyncService::channelUrl($channel, 'https', 'admin.example.com'), $this->queryYielding([
            $this->createProduct(7, ['fr_FR' => 't-shirt-genial', 'en_US' => 'awesome-t-shirt']),
            $this->createProduct(8, ['en_US' => 'english-only']), // no page in the channel's language
        ]));

        $this->assertSame(1, $count);
        $this->assertSame([['website' => self::WEBSITE_ID, 'url' => 'https://shop.example.fr/fr_FR/products/t-shirt-genial', 'extId' => '7']], $created);
    }

    public function testAChannelWithoutHostnameIsServedOnTheCurrentHost(): void
    {
        $this->assertSame('https://admin.example.com', SyncService::channelUrl($this->createChannel('WEB', null, 'en_US'), 'https', 'admin.example.com'));
    }

    private function createChannel(string $code, ?string $hostname, string $localeCode): ChannelInterface
    {
        $locale = $this->createMock(LocaleInterface::class);
        $locale->method('getCode')->willReturn($localeCode);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);
        $channel->method('getHostname')->willReturn($hostname);
        $channel->method('getDefaultLocale')->willReturn($locale);

        return $channel;
    }

    /**
     * @param array<string, string> $slugs by locale code
     */
    private function createProduct(int $id, array $slugs): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn($id);
        $product->method('getTranslation')->willReturnCallback(function (?string $localeCode) use ($slugs): ProductTranslationInterface {
            $translation = $this->createMock(ProductTranslationInterface::class);
            $translation->method('getSlug')->willReturn($slugs[$localeCode] ?? null);

            return $translation;
        });

        return $product;
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function queryYielding(array $rows): Query
    {
        $query = $this->createStub(Query::class);
        $query->method('toIterable')->willReturn($rows);

        return $query;
    }

    /**
     * @param array<int, array<string, string>> $websites the account's websites, each read by GET /websites/{id}
     * @param array<int, mixed> $created the products created, filled by the fake API
     * @param array<string, array<string, string>> $channels the pricee_io_sync.channels configuration
     */
    private function createService(array $websites, array &$created = [], array $channels = []): SyncService
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($websites, &$created): MockResponse {
            if ('GET' === $method && str_contains($url, '/websites/')) {
                foreach ($websites as $website) {
                    if (str_ends_with($url, (string) substr($website['@id'], strrpos($website['@id'], '/') ?: 0))) {
                        return new MockResponse((string) json_encode($website), ['http_code' => 200]);
                    }
                }

                return new MockResponse('{}', ['http_code' => 404]);
            }
            if ('POST' === $method && str_ends_with($url, '/login')) {
                // Each channel logs in with its own API key
                $clientId = $options['normalized_headers']['x-client-id'][0] ?? '';

                return new MockResponse((string) json_encode(['token' => 'token', 'website' => 'X-CLIENT-ID: fr_client' === $clientId ? self::WEBSITE_ID : null]), ['http_code' => 200]);
            }
            if ('GET' === $method && str_contains($url, '/website_products?')) {
                return new MockResponse((string) json_encode(['member' => []]), ['http_code' => 200]);
            }
            if ('POST' === $method && str_ends_with($url, '/website_products')) {
                $created[] = json_decode((string) $options['body'], true);

                return new MockResponse('{}', ['http_code' => 201]);
            }

            return new MockResponse((string) json_encode(['error' => 'unexpected request ' . $method . ' ' . $url]), ['http_code' => 500]);
        });

        // The shop route of a Sylius install whose URLs carry the locale
        $context = new RequestContext();
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('getContext')->willReturn($context);
        $router->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters): string => '/' . $context->getParameter('_locale') . '/products/' . $parameters['slug'],
        );

        return new SyncService(
            new ApiService($httpClient, new Params(new ParameterBag(['priceeio.channels' => $channels]))),
            $router,
            new NullLogger(),
        );
    }
}
