<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Service;

final class SyncService
{
    private ApiService $apiService;

    public function __construct(
        ApiService $apiService,
    ) {
        $this->apiService = $apiService;
    }

    public function syncProducts(
        array $products,
        string $clientId,
        string $key,
    ): void {
        $bearer = $this->apiService->getBearer($clientId, $key);
    }
}
