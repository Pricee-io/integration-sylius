<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Service;

use Doctrine\ORM\EntityManagerInterface;
use PriceeIO\SyncPlugin\Helper\Params;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface as CoreProductVariantInterface;
use Sylius\Component\Product\Model\ProductTranslationInterface;
use Sylius\Component\Product\Repository\ProductTranslationRepositoryInterface;

/**
 * Applies a price update received from the Pricee.io webhook to the channel
 * pricing of the product's channel. Writing a price is the critical action:
 * in any doubtful case the entry is skipped and logged, never guessed.
 */
final class WebhookPriceUpdater
{
    public const UPDATED_PRICE = 'updated_price';

    public const UNCHANGED = 'unchanged';

    public const SKIPPED_INVALID_ENTRY = 'skipped_invalid_entry';

    public const SKIPPED_NO_READ_PRICE = 'skipped_no_read_price';

    public const SKIPPED_UNKNOWN_PRODUCT = 'skipped_unknown_product';

    public const SKIPPED_DISABLED = 'skipped_disabled';

    public const SKIPPED_UNSUPPORTED_TYPE = 'skipped_unsupported_type';

    public const SKIPPED_CHANNEL = 'skipped_channel';

    public const SKIPPED_PROMOTION = 'skipped_promotion';

    public const SKIPPED_PRICE_CHANGED_IN_SHOP = 'skipped_price_changed_in_shop';

    public const SKIPPED_BELOW_MINIMUM = 'skipped_below_minimum';

    public const FAILED = 'failed';

    /**
     * @param ProductTranslationRepositoryInterface<ProductTranslationInterface> $productTranslationRepository
     * @param ChannelRepositoryInterface<ChannelInterface> $channelRepository
     */
    public function __construct(
        private ProductTranslationRepositoryInterface $productTranslationRepository,
        private ChannelRepositoryInterface $channelRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private Params $params,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     * @param ?string $channelCode the channel whose own secret signed the call,
     *                             null for the shared secret (the channels without their own one)
     *
     * @return string the outcome of the entry (one of the constants)
     */
    public function updateFromEntry(array $entry, ?string $channelCode = null): string
    {
        $url = $entry['url'] ?? null;
        $amount = $entry['priceAmount'] ?? null;
        $currency = isset($entry['priceCurrency']) && is_string($entry['priceCurrency'])
            ? strtoupper($entry['priceCurrency'])
            : '';

        if (!is_string($url) || '' === $url || !is_numeric($amount) || (float) $amount <= 0 || '' === $currency) {
            return $this->skip(self::SKIPPED_INVALID_ENTRY, 'invalid url, price or currency', $entry);
        }

        // Pricee computes the new price from the price it last read on the
        // page: when the merchant changed it since, their change wins
        $readPrice = $entry['currentPriceAmount'] ?? null;
        if (!is_numeric($readPrice)) {
            return $this->skip(self::SKIPPED_NO_READ_PRICE, 'no current price sent to compare with the shop price', $entry);
        }

        $product = $this->resolveProduct($entry['extId'] ?? null, $url);
        if (null === $product) {
            return $this->skip(self::SKIPPED_UNKNOWN_PRODUCT, 'no single product matches the entry', $entry);
        }

        if (!$product->isEnabled()) {
            return $this->skip(self::SKIPPED_DISABLED, 'product disabled', $entry);
        }

        // Only products with one variant: the variants of a product can have
        // their own prices, and Pricee read only the one shown on the page
        $variants = array_values(array_filter(
            $product->getEnabledVariants()->toArray(),
            static fn (mixed $variant): bool => $variant instanceof CoreProductVariantInterface,
        ));
        if (1 !== count($variants)) {
            return $this->skip(self::SKIPPED_UNSUPPORTED_TYPE, 'product with several variants', $entry);
        }

        $channel = $this->resolveChannel($product, $currency, $url, $channelCode);
        if (null === $channel) {
            return $this->skip(self::SKIPPED_CHANNEL, 'no single channel of the product matches the webhook secret, the currency and the URL', $entry);
        }

        /** @var CoreProductVariantInterface $variant */
        $variant = $variants[0];
        $channelPricing = $variant->getChannelPricingForChannel($channel);
        $currentPrice = $channelPricing?->getPrice();
        if (!$channelPricing instanceof ChannelPricingInterface || null === $currentPrice) {
            return $this->skip(self::SKIPPED_CHANNEL, 'no price on the channel', $entry);
        }

        // A catalog promotion recomputes the price from the original one, and
        // a shown original price is a discount: never write over either
        $originalPrice = $channelPricing->getOriginalPrice();
        if (!$channelPricing->getAppliedPromotions()->isEmpty() || (null !== $originalPrice && $originalPrice > $currentPrice)) {
            return $this->skip(self::SKIPPED_PROMOTION, 'has a promotion or a shown original price', $entry);
        }

        // Sylius stores prices as integers in minor units
        $price = (int) round((float) $amount * 100);
        // Checked first: a retried call whose first attempt was applied finds
        // the price already set, not changed in the shop
        if ($price === $currentPrice) {
            return self::UNCHANGED;
        }

        if ($currentPrice !== (int) round((float) $readPrice * 100)) {
            return $this->skip(self::SKIPPED_PRICE_CHANGED_IN_SHOP, sprintf('the shop price %d is no longer the one Pricee read', $currentPrice), $entry);
        }

        $minimumPrice = $channelPricing->getMinimumPrice();
        if ($minimumPrice > 0 && $price < $minimumPrice) {
            return $this->skip(self::SKIPPED_BELOW_MINIMUM, sprintf('new price %d is below the minimum price %d', $price, $minimumPrice), $entry);
        }

        // A write that fails is this entry's outcome, not the whole call's: the
        // entries already written stay reported as such. After a database error
        // the entity manager is closed, so the following writes fail the same way
        try {
            $channelPricing->setPrice($price);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Pricee webhook: price update failed for product "%s": %s', (string) $product->getCode(), $e->getMessage()));

            return self::FAILED;
        }

        $this->logger->info(sprintf(
            'Pricee webhook: price updated for product "%s" on channel "%s" | Old: %d -> New: %d.',
            (string) $product->getCode(),
            (string) $channel->getCode(),
            $currentPrice,
            $price,
        ));

        return self::UPDATED_PRICE;
    }

    /**
     * The product of the entry: by its extId (the Sylius product ID sent at
     * sync) when present, never falling back to the URL for a stale one, else
     * by the slug of its URL when exactly one product has it.
     */
    private function resolveProduct(mixed $extId, string $url): ?ProductInterface
    {
        if (null !== $extId && '' !== $extId) {
            $product = is_numeric($extId) ? $this->entityManager->find(ProductInterface::class, (int) $extId) : null;

            return $product instanceof ProductInterface ? $product : null;
        }

        $slug = $this->extractSlug($url);
        if ('' === $slug) {
            return null;
        }

        $products = [];
        /** @var array<ProductTranslationInterface> $translations */
        $translations = $this->productTranslationRepository->findBy(['slug' => $slug]);
        foreach ($translations as $translation) {
            $product = $translation->getTranslatable();
            if ($product instanceof ProductInterface) {
                $products[spl_object_id($product)] = $product;
            }
        }

        return 1 === count($products) ? reset($products) : null;
    }

    /**
     * The channel Pricee read the price on: among the product's channels the
     * call may update, in the entry's currency, the one whose hostname is the
     * URL's when several remain.
     */
    private function resolveChannel(ProductInterface $product, string $currency, string $url, ?string $channelCode): ?ChannelInterface
    {
        // A channel with its own secret is only updated by calls signed with it
        $ownSecrets = $this->params->getChannelWebhookSecrets();
        $channels = array_values(array_filter(
            $this->channelRepository->findAll(),
            static fn (mixed $channel): bool => $channel instanceof ChannelInterface &&
                (null === $channelCode ? !isset($ownSecrets[(string) $channel->getCode()]) : $channel->getCode() === $channelCode) &&
                $product->hasChannel($channel) &&
                $channel->getBaseCurrency()?->getCode() === $currency,
        ));

        if (count($channels) > 1) {
            $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
            $channels = array_values(array_filter(
                $channels,
                static fn (ChannelInterface $channel): bool => '' !== $host && strtolower((string) $channel->getHostname()) === $host,
            ));
        }

        return 1 === count($channels) ? $channels[0] : null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function skip(string $outcome, string $reason, array $entry): string
    {
        $this->logger->warning(sprintf('Pricee webhook: entry skipped, %s.', $reason), [
            'url' => $entry['url'] ?? null,
            'extId' => $entry['extId'] ?? null,
        ]);

        return $outcome;
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
