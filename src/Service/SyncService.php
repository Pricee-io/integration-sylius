<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Service;

use Doctrine\ORM\Query;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the products of one channel to the Pricee.io website of that channel.
 */
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

    /**
     * @param string $websiteUrl the channel's front URL (see channelUrl())
     */
    public function syncProducts(
        ChannelInterface $channel,
        string $websiteUrl,
        Query $query,
    ): int {
        $login = $this->apiService->login($channel->getCode());
        $bearer = $login['token'];
        $websiteId = $this->resolveWebsiteId($login, $websiteUrl);

        // Product pages are read in the channel's default language, which the admin's may not be
        $localeCode = $channel->getDefaultLocale()?->getCode();

        $productsCount = 0;
        /** @var ProductInterface $product */
        foreach ($query->toIterable() as $product) {
            try {
                $this->apiService->createOrUpdateProduct(
                    $bearer,
                    $websiteId,
                    $this->productUrl($product, $websiteUrl, $localeCode),
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
     * Front URL of a channel: its own hostname, or the current host for a
     * channel without one (Sylius then serves it on any host).
     */
    public static function channelUrl(ChannelInterface $channel, string $scheme, string $currentHost): string
    {
        $hostname = $channel->getHostname();

        return $scheme . '://' . (null !== $hostname && '' !== $hostname ? $hostname : $currentHost);
    }

    /**
     * The Pricee website to sync to: the one the API key is scoped to, which
     * must be this channel's website.
     *
     * @param array{token: string, website: ?string} $login
     */
    public function resolveWebsiteId(array $login, string $websiteUrl): string
    {
        if (null === $login['website']) {
            throw new \RuntimeException('This API key is not tied to a website. Create an API key from the Integrations page of this channel\'s website on Pricee.');
        }

        $normalizedWebsiteUrl = rtrim($websiteUrl, '/');
        foreach ($this->apiService->getWebsites($login['token']) as $w) {
            if ($w['@id'] === $login['website'] && $this->websiteHost((string) $w['url']) !== $this->websiteHost($normalizedWebsiteUrl)) {
                throw new \RuntimeException(sprintf(
                    'This API key belongs to the Pricee website %s, not to this channel (%s). Use an API key created from the Integrations page of this channel\'s website on Pricee.',
                    $w['url'],
                    $normalizedWebsiteUrl,
                ));
            }
        }

        return $login['website'];
    }

    /**
     * The product page on the channel's domain, in the channel's language.
     */
    private function productUrl(ProductInterface $product, string $websiteUrl, ?string $localeCode): string
    {
        if (null === $localeCode) {
            throw new \RuntimeException('the channel has no default locale');
        }

        $slug = $product->getTranslation($localeCode)->getSlug();
        if (null === $slug || '' === $slug) {
            throw new \RuntimeException('no slug in ' . $localeCode);
        }

        // Set on the context, the locale fills a {_locale} the shop routes may have, never a query string
        $context = $this->router->getContext();
        $previousLocale = $context->getParameter('_locale');
        $context->setParameter('_locale', $localeCode);

        try {
            $path = $this->router->generate('sylius_shop_product_show', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_PATH);
        } finally {
            $context->setParameter('_locale', $previousLocale);
        }

        return rtrim($websiteUrl, '/') . $path;
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
