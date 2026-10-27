<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface as CoreProductVariantInterface;
use Sylius\Component\Product\Model\ProductTranslationInterface;
use Sylius\Component\Product\Repository\ProductTranslationRepositoryInterface;

/**
 * Applies price updates received from the Pricee.io webhook to Sylius channel pricings.
 */
final class WebhookPriceUpdater
{
    /**
     * @param ProductTranslationRepositoryInterface<ProductTranslationInterface> $productTranslationRepository
     * @param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(
        private ProductTranslationRepositoryInterface $productTranslationRepository,
        private ChannelRepositoryInterface $channelRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return int the number of channel pricings updated
     */
    public function updateFromEntry(array $entry): int
    {
        $url = $entry['url'] ?? null;
        $amount = $entry['bestPriceAmount'] ?? null;

        if (!is_string($url) || '' === $url || !is_numeric($amount) || (float) $amount <= 0) {
            $this->logger->warning('Pricee webhook: invalid entry skipped.', ['entry' => $entry]);

            return 0;
        }

        // Sylius stores prices as integers in minor units
        $price = (int) round((float) $amount * 100);

        $currency = isset($entry['bestPriceCurrency']) && is_string($entry['bestPriceCurrency'])
            ? strtoupper($entry['bestPriceCurrency'])
            : null;

        if (null === $currency || '' === $currency) {
            $this->logger->warning('Pricee webhook: entry without currency skipped.', ['url' => $url]);

            return 0;
        }

        $slug = $this->extractSlug($url);
        if ('' === $slug) {
            $this->logger->warning('Pricee webhook: could not extract product slug from URL.', ['url' => $url]);

            return 0;
        }

        /** @var array<ProductTranslationInterface> $translations */
        $translations = $this->productTranslationRepository->findBy(['slug' => $slug]);

        $updated = 0;
        $channels = array_filter(
            $this->channelRepository->findAll(),
            static fn (mixed $channel): bool => $channel instanceof ChannelInterface,
        );
        $seenProductCodes = [];

        foreach ($translations as $translation) {
            $product = $translation->getTranslatable();
            if (!$product instanceof ProductInterface || isset($seenProductCodes[$product->getCode()])) {
                continue;
            }

            /** @psalm-suppress PossiblyNullArrayOffset */
            $seenProductCodes[$product->getCode()] = true;

            foreach ($product->getEnabledVariants() as $variant) {
                if (!$variant instanceof CoreProductVariantInterface) {
                    continue;
                }

                foreach ($channels as $channel) {
                    $channelCurrency = $channel->getBaseCurrency()?->getCode();
                    if ($channelCurrency !== $currency) {
                        continue;
                    }

                    $channelPricing = $variant->getChannelPricingForChannel($channel);
                    if (null === $channelPricing) {
                        continue;
                    }

                    $channelPricing->setPrice($price);
                    ++$updated;

                    $this->logger->info(sprintf(
                        'Pricee webhook: price updated for product "%s" on channel "%s" | New price: %d.',
                        (string) $product->getCode(),
                        (string) $channel->getCode(),
                        $price,
                    ));
                }
            }
        }

        if ($updated > 0) {
            $this->entityManager->flush();
        }

        return $updated;
    }

    /**
     * Product URLs sent to Pricee.io are generated from the product slug
     * (route `sylius_shop_product_show`), so the slug is the last path segment.
     */
    private function extractSlug(string $url): string
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => '' !== $segment,
        ));

        if ([] === $segments) {
            return '';
        }

        return rawurldecode((string) end($segments));
    }
}
