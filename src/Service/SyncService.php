<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Service;

use Doctrine\ORM\Query;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SyncService
{
    private ApiService $apiService;

    private UrlGeneratorInterface $router;

    private LoggerInterface $logger;

    public function __construct(
        ApiService $apiService,
        UrlGeneratorInterface $router,
        LoggerInterface $logger,
    ) {
        $this->apiService = $apiService;
        $this->router = $router;
        $this->logger = $logger;
    }

    public function syncProducts(
        string $websiteUrl,
        Query $query,
    ): int {
        $login = $this->apiService->login();
        $bearer = $login['token'];
        $websiteId = $this->resolveWebsiteId($login, $websiteUrl);

        $productsCount = 0;
        /** @var ProductInterface $product */
        foreach ($query->toIterable() as $product) {
            $productUrl = $this->router->generate('sylius_shop_product_show', [
                'slug' => $product->getTranslation()->getSlug(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            try {
                $this->apiService->createOrUpdateProduct(
                    $bearer,
                    $websiteId,
                    $productUrl,
                    (string) $product->getId(),
                );
            } catch (\Exception $e) {
                $this->logger->error('Pricee sync: failed to sync product ID ' . $product->getId() . ': ' . $e->getMessage());

                continue; // go to next product
            }
            ++$productsCount;
        }

        return $productsCount;
    }

    /**
     * The Pricee website to sync to: the one the API key is scoped to, which
     * must be this shop's website.
     *
     * @param array{token: string, website: ?string} $login
     */
    public function resolveWebsiteId(array $login, string $websiteUrl): string
    {
        if (null === $login['website']) {
            throw new \RuntimeException('This API key is not tied to a website. Create an API key from the Integrations page of this shop\'s website on Pricee.');
        }

        $normalizedWebsiteUrl = rtrim($websiteUrl, '/');
        foreach ($this->apiService->getWebsites($login['token']) as $w) {
            if ($w['@id'] === $login['website'] && $this->websiteHost((string) $w['url']) !== $this->websiteHost($normalizedWebsiteUrl)) {
                throw new \RuntimeException(sprintf(
                    'This API key belongs to the Pricee website %s, not to this shop (%s). Use an API key created from the Integrations page of this shop\'s website on Pricee.',
                    $w['url'],
                    $normalizedWebsiteUrl,
                ));
            }
        }

        return $login['website'];
    }

    /**
     * Host of a URL, compared like Pricee checks that a product belongs to a
     * website: case-insensitive, without "www.".
     */
    private function websiteHost(string $url): string
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));

        return (string) preg_replace('/^www\./', '', $host);
    }
}
