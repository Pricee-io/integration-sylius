<?php

declare(strict_types=1);

namespace Tests\PriceeIO\SyncPlugin\Unit\Service;

use PHPUnit\Framework\TestCase;
use PriceeIO\SyncPlugin\Helper\Params;
use PriceeIO\SyncPlugin\Service\ApiService;
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
