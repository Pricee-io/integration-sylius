<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Service;

use PriceeIO\SyncPlugin\Helper\Params;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApiService
{
    public const DEFAULT_BASE_URL = 'https://app.pricee.io/api/v1/';

    /** A token lasts an hour: reused 45 minutes, so a large sync logs in once instead of once per batch */
    private const LOGIN_TTL = 2700;

    private HttpClientInterface $httpClient;

    private Params $params;

    private string $baseUrl;

    public function __construct(
        HttpClientInterface $httpClient,
        Params $params,
        // Where logins are kept between the requests of a sync
        private ?CacheItemPoolInterface $cacheApp = null,
        // Overridable with PRICEEIO_API_BASE_URL, to sync against a local Pricee or a fake API
        string $apiBaseUrl = self::DEFAULT_BASE_URL,
    ) {
        $this->httpClient = $httpClient;
        $this->params = $params;
        $this->baseUrl = rtrim('' !== $apiBaseUrl ? $apiBaseUrl : self::DEFAULT_BASE_URL, '/') . '/';
    }

    /**
     * Log in with the API key of a channel. Returns the JWT and, for a key
     * scoped to a website, the IRI of that website (null for an account-wide key).
     *
     * The login of a previous batch is reused (Pricee allows few logins per
     * minute); $fresh forces a new one, when a reused token was refused.
     *
     * @return array{token: string, website: ?string}
     */
    public function login(?string $channelCode = null, bool $fresh = false): array
    {
        $credentials = $this->params->getCredentials($channelCode);

        $item = $this->cacheApp?->getItem('priceeio_login_' . hash('sha256', $credentials['clientId'] . '|' . $credentials['apiKey']));
        $cached = null !== $item && !$fresh && $item->isHit() ? $item->get() : null;
        if (is_array($cached) && is_string($cached['token'] ?? null)) {
            return ['token' => $cached['token'], 'website' => is_string($cached['website'] ?? null) ? $cached['website'] : null];
        }

        $login = $this->requestLogin($credentials);
        if (null !== $item) {
            $item->set($login);
            $item->expiresAfter(self::LOGIN_TTL);
            $this->cacheApp?->save($item);
        }

        return $login;
    }

    /**
     * @param array{clientId: string, apiKey: string} $credentials
     *
     * @return array{token: string, website: ?string}
     */
    private function requestLogin(array $credentials): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . 'login', [
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

            return [
                'token' => (string) $data['token'],
                'website' => isset($data['website']) && is_string($data['website']) ? $data['website'] : null,
            ];
        } catch (TransportExceptionInterface | ClientExceptionInterface $e) {
            throw new \RuntimeException('Failed to fetch bearer token: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The website an API key is scoped to, read directly: a key can always
     * read its own website, however many websites the account has.
     *
     * @return array<string, mixed>
     */
    public function getWebsite(string $bearer, string $websiteId): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . 'websites/' . $this->extractUuid($websiteId), [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'Authorization' => 'Bearer ' . $bearer,
                ],
            ]);

            $status = $response->getStatusCode();
            if (200 !== $status) {
                // The status as code: a 401 tells a reused token has expired
                throw new \RuntimeException(sprintf(
                    'Failed to fetch the website of the API key. Status: %d. Response: %s',
                    $status,
                    $response->getContent(false),
                ), $status);
            }

            return $response->toArray(false);
        } catch (TransportExceptionInterface | ClientExceptionInterface $e) {
            throw new \RuntimeException('Failed to fetch the website of the API key: ' . $e->getMessage(), 0, $e);
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

                // Only a product synced without extId can be adopted by URL:
                // one with another extId belongs to another shop product
                $owner = $products[0]['extId'] ?? null;
                if (null !== $owner && '' !== $owner) {
                    throw new \RuntimeException(sprintf('URL %s is already tracked on Pricee for product ID %s', $productUrl, is_scalar($owner) ? (string) $owner : ''));
                }
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
                    $updateResponse = $this->httpClient->request('PATCH', $this->baseUrl . 'website_products/' . (string) $existingId, [
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
            $createResponse = $this->httpClient->request('POST', $this->baseUrl . 'website_products', [
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

        $response = $this->httpClient->request('GET', $this->baseUrl . 'website_products?' . $queryParams, [
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
