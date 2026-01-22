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

    public function createProduct(string $bearer, string $websiteId, string $productUrl): array
    {
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . 'website_products', [
                'headers' => [
                    'Content-Type' => 'application/ld+json',
                    'Authorization' => 'Bearer ' . $bearer,
                ],
                'json' => [
                    'website' => $websiteId,
                    'url' => $productUrl,
                ],
            ]);

            $status = $response->getStatusCode();

            if (201 !== $status) {
                throw new \RuntimeException(sprintf(
                    'Failed to create product. Status: %d. Response: %s',
                    $status,
                    $response->getContent(false),
                ));
            }

            $data = $response->toArray(false);

            return $data;
        } catch (TransportExceptionInterface | ClientExceptionInterface $e) {
            throw new \RuntimeException('Failed to create product: ' . $e->getMessage(), 0, $e);
        }
    }
}
