<?php

declare(strict_types=1);

namespace Tests\PriceeIO\SyncPlugin\Unit\Service;

use PHPUnit\Framework\TestCase;
use PriceeIO\SyncPlugin\Helper\Params;
use PriceeIO\SyncPlugin\Service\ApiService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApiServiceTest extends TestCase
{
    private const WEBSITE_ID = '/api/v1/websites/11111111-1111-4111-8111-111111111111';

    public function testAdoptsByUrlAProductSyncedWithoutExtId(): void
    {
        $patches = [];
        $this->createService(['id' => 'wp-1', 'url' => 'https://shop.example/products/t-shirt', 'extId' => null], $patches)
            ->createOrUpdateProduct('token', self::WEBSITE_ID, 'https://shop.example/products/t-shirt', '7');

        $this->assertSame([['extId' => '7']], $patches);
    }

    public function testNeverTakesOverByUrlTheProductOfAnotherExtId(): void
    {
        // Product 9 had this slug before product 7: its record and price history stay its own
        $patches = [];
        $service = $this->createService(['id' => 'wp-1', 'url' => 'https://shop.example/products/t-shirt', 'extId' => '9'], $patches);

        try {
            $service->createOrUpdateProduct('token', self::WEBSITE_ID, 'https://shop.example/products/t-shirt', '7');
            $this->fail('The product of another extId must not be adopted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already tracked on Pricee for product ID 9', $e->getMessage());
        }
        $this->assertSame([], $patches);
    }

    public function testReusesTheLoginOfAPreviousBatchUnlessAFreshOneIsAsked(): void
    {
        // Pricee allows few logins per minute: a large sync must not log in at each batch
        $logins = 0;
        $httpClient = new MockHttpClient(function () use (&$logins): MockResponse {
            ++$logins;

            return new MockResponse((string) json_encode(['token' => 'token-' . $logins, 'website' => self::WEBSITE_ID]), ['http_code' => 200]);
        });
        $service = new ApiService($httpClient, new Params(new ParameterBag(['priceeio.client_id' => 'client', 'priceeio.api_key' => 'key'])), new ArrayAdapter());

        $this->assertSame('token-1', $service->login()['token']);
        $this->assertSame('token-1', $service->login()['token']);
        $this->assertSame('token-2', $service->login(null, true)['token']);
        $this->assertSame(2, $logins);
    }

    public function testCallsTheApiBaseUrlGiven(): void
    {
        $urls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse((string) json_encode(['token' => 'token', 'website' => self::WEBSITE_ID]), ['http_code' => 200]);
        });

        (new ApiService($httpClient, new Params(new ParameterBag([])), null, 'http://pricee.test/api/v1'))->login();

        $this->assertSame(['http://pricee.test/api/v1/login'], $urls);
    }

    /**
     * @param array<string, mixed> $productByUrl the website product the URL lookup finds
     * @param array<int, mixed> $patches the PATCH bodies sent, filled by the fake API
     */
    private function createService(array $productByUrl, array &$patches): ApiService
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($productByUrl, &$patches): MockResponse {
            if ('GET' === $method && str_contains($url, 'extId=')) {
                return new MockResponse((string) json_encode(['member' => []]), ['http_code' => 200]);
            }
            if ('GET' === $method && str_contains($url, 'url=')) {
                return new MockResponse((string) json_encode(['member' => [$productByUrl]]), ['http_code' => 200]);
            }
            if ('PATCH' === $method) {
                $patches[] = json_decode((string) $options['body'], true);

                return new MockResponse('{}', ['http_code' => 200]);
            }

            return new MockResponse('{}', ['http_code' => 500]);
        });

        return new ApiService($httpClient, new Params(new ParameterBag([])));
    }
}
