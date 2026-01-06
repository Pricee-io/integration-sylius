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

    public function getBearer(string $clientId, string $key): string
    {
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . 'login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'client_id' => $clientId,
                    'key' => $key,
                ]),
            ]);

            $data = $response->toArray();
            dd($data);

            if (!isset($data['access_token'])) {
                throw new \RuntimeException('API did not return an access token');
            }

            return $data['access_token'];
        } catch (TransportExceptionInterface | ClientExceptionInterface $e) {
            throw new \RuntimeException('Failed to fetch bearer token: ' . $e->getMessage());
        }
    }
}
