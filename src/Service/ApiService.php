<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Service;

use PriceeIO\SyncPlugin\Helper\Params;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApiService
{
    private const BASE_URL = 'https://app.pricee.io/api/v1/';

    private HttpClientInterface $httpClient;

    private Params $params;

    public function __construct(
        HttpClientInterface $httpClient,
        Params $params,
    ) {
        $this->httpClient = $httpClient;
        $this->params = $params;
    }

    public function getBearer(): string
    {
        $credentials = $this->params->getCredentials();

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . 'login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-CLIENT-ID' => $credentials['clientId'],
                    'X-API-KEY' => $credentials['apiKey'],
                ],
            ]);

            $status = $response->getStatusCode();

            if (200 !== $status) {
                throw new \RuntimeException(sprintf(
                    'Failed to login with API key. Status: %d. Response: %s',
                    $status,
                    $response->getContent(false),
                ));
            }

            $data = $response->toArray(false);

            if (!isset($data['token'])) {
                throw new \RuntimeException('JWT token not returned from API key login.');
            }

            return $data['token'];
        } catch (TransportExceptionInterface | ClientExceptionInterface $e) {
            throw new \RuntimeException('Failed to fetch bearer token: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getWebsites(string $bearer): array
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . 'websites', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $bearer,
                ],
            ]);

            $status = $response->getStatusCode();

            if (200 !== $status) {
                throw new \RuntimeException(sprintf(
                    'Failed to fetch websites. Status: %d. Response: %s',
                    $status,
                    $response->getContent(false),
                ));
            }

            $data = $response->toArray(false);

            return $data['member'];
        } catch (TransportExceptionInterface | ClientExceptionInterface $e) {
            throw new \RuntimeException('Failed to fetch websites: ' . $e->getMessage(), 0, $e);
        }
    }

    public function createWebsite(string $bearer, string $url): array
    {
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . 'websites', [
                'headers' => [
                    'Content-Type' => 'application/ld+json',
                    'Authorization' => 'Bearer ' . $bearer,
                ],
                'json' => [
                    'url' => $url,
                ],
            ]);

            $status = $response->getStatusCode();

            if (201 !== $status) {
                throw new \RuntimeException(sprintf(
                    'Failed to create website. Status: %d. Response: %s',
                    $status,
                    $response->getContent(false),
                ));
            }

            $data = $response->toArray(false);

            return $data;
        } catch (TransportExceptionInterface | ClientExceptionInterface $e) {
            throw new \RuntimeException('Failed to create website: ' . $e->getMessage(), 0, $e);
        }
    }

    public function extractUuid(string $id): string
    {
        preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $id, $matches);
        $uuid = $matches[0] ?? null;
        if (!$uuid) {
            throw new \InvalidArgumentException('Invalid ID');
        }

        return $uuid;
    }

    /**
     * Create a product, or update it when it already exists.
     *
     * The product is matched by website + extId (the internal shop product
     * ID, which stays stable when the URL changes). Products synced before
     * extId existed are adopted via a fallback lookup by URL, and get their
     * extId set so later syncs match them directly.
     */
    public function createOrUpdateProduct(
        string $bearer,
        string $websiteId,
        string $productUrl,
        string $productExtId,
    ): void {
        $websiteUuid = $this->extractUuid($websiteId);

        try {
            $products = $this->lookupProducts($bearer, $websiteUuid, 'extId', $productExtId);
            if ([] === $products) {
                $products = $this->lookupProducts($bearer, $websiteUuid, 'url', $productUrl);
            }

            if ([] !== $products) {
                // Product exists: update its url only when it changed, and
                // set extId when adopting a product synced without one
                $existing = $products[0];
                $existingUrl = $existing['url'] ?? '';
                if (!is_string($existingUrl)) {
                    $existingUrl = '';
                }
                $existingId = $existing['id'] ?? '';
                if (!is_scalar($existingId)) {
                    $existingId = '';
                }
                $patch = [];
                if (rtrim($existingUrl, '/') !== rtrim($productUrl, '/')) {
                    $patch['url'] = $productUrl;
                }
                if (($existing['extId'] ?? null) !== $productExtId) {
                    $patch['extId'] = $productExtId;
                }

                if ([] !== $patch) {
                    $updateResponse = $this->httpClient->request('PATCH', self::BASE_URL . 'website_products/' . (string) $existingId, [
                        'headers' => [
                            'Content-Type' => 'application/merge-patch+json',
                            'Authorization' => 'Bearer ' . $bearer,
                        ],
                        'json' => $patch,
                    ]);

                    if (200 !== $updateResponse->getStatusCode()) {
                        throw new \RuntimeException(sprintf(
                            'Failed to update product. Status: %d. Response: %s',
                            $updateResponse->getStatusCode(),
                            $updateResponse->getContent(false),
                        ));
                    }
                }

                return;
            }

            // Product does not exist: create it
            $createResponse = $this->httpClient->request('POST', self::BASE_URL . 'website_products', [
                'headers' => [
                    'Content-Type' => 'application/ld+json',
                    'Authorization' => 'Bearer ' . $bearer,
                ],
                'json' => [
                    'website' => $websiteId,
                    'url' => $productUrl,
                    'extId' => $productExtId,
                ],
            ]);
            if (201 !== $createResponse->getStatusCode()) {
                throw new \RuntimeException(sprintf(
                    'Failed to create product. Status: %d. Response: %s',
                    $createResponse->getStatusCode(),
                    $createResponse->getContent(false),
                ));
            }
        } catch (TransportExceptionInterface | ClientExceptionInterface $e) {
            throw new \RuntimeException('Failed to create/update product: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Look up website products of a website matching a filter (extId or url).
     *
     * @return array<int, array<string, mixed>>
     */
    private function lookupProducts(string $bearer, string $websiteUuid, string $filterKey, string $filterValue): array
    {
        $queryParams = http_build_query([
            'website' => $websiteUuid,
            $filterKey => $filterValue,
        ]);

        $response = $this->httpClient->request('GET', self::BASE_URL . 'website_products?' . $queryParams, [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearer,
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf(
                'Failed to lookup product. Status: %d. Response: %s',
                $response->getStatusCode(),
                $response->getContent(false),
            ));
        }

        $products = $response->toArray(false)['member'] ?? [];

        return is_array($products) ? $products : [];
    }
}
