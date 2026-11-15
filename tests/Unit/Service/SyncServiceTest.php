<?php

declare(strict_types=1);

namespace Tests\PriceeIO\SyncPlugin\Unit\Service;

use PHPUnit\Framework\TestCase;
use PriceeIO\SyncPlugin\Helper\Params;
use PriceeIO\SyncPlugin\Service\ApiService;
use PriceeIO\SyncPlugin\Service\SyncService;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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

    /**
     * @param array<int, array<string, string>> $websites what GET /websites returns
     */
    private function createService(array $websites): SyncService
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($websites): MockResponse {
            if ('GET' === $method && str_contains($url, '/websites')) {
                return new MockResponse((string) json_encode(['member' => $websites]), ['http_code' => 200]);
            }

            return new MockResponse((string) json_encode(['error' => 'unexpected request ' . $method . ' ' . $url]), ['http_code' => 500]);
        });

        return new SyncService(
            new ApiService($httpClient, new Params(new ParameterBag())),
            $this->createMock(UrlGeneratorInterface::class),
            new NullLogger(),
        );
    }
}
