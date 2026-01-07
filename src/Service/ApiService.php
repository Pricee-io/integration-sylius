<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Service;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApiService
{
    private const BASE_URL = 'https://app.pricee.io/api/v1/';

    private HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
    ) {
        $this->httpClient = $httpClient;
    }

    public function getBearer(string $clientId, string $apiKey): string
    {
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . 'login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-CLIENT-ID' => $clientId,
                    'X-API-KEY' => $apiKey,
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
}
