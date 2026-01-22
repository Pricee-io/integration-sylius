<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Service;

use Doctrine\ORM\Query;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SyncService
{
    private ApiService $apiService;

    private UrlGeneratorInterface $router;

    public function __construct(
        ApiService $apiService,
        UrlGeneratorInterface $router,
    ) {
        $this->apiService = $apiService;
        $this->router = $router;
    }

    public function syncProducts(
        string $websiteUrl,
        Query $query,
    ): int {
        $bearer = $this->apiService->getBearer();
        $websites = $this->apiService->getWebsites($bearer);

        $normalizedWebsiteUrl = rtrim($websiteUrl, '/');
        $websiteId = null;

        // Look for existing website
        foreach ($websites as $w) {
            if (rtrim($w['url'], '/') === $normalizedWebsiteUrl) {
                $websiteId = $w['@id'];

                break;
            }
        }

        // If not found, create it
        if (!$websiteId) {
            $website = $this->apiService->createWebsite($bearer, $normalizedWebsiteUrl);
            $websiteId = $website['@id'];
        }

        $productsCount = 0;
        foreach ($query->toIterable() as $product) {
            if ($productsCount >= 500) {
                break;
            }
            $productUrl = $this->router->generate('sylius_shop_product_show', [
                'slug' => $product->getTranslation()->getSlug(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $this->apiService->createProduct($bearer, $websiteId, $productUrl);
            ++$productsCount;
        }

        return $productsCount;
    }
}
