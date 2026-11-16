<?php

declare(strict_types=1);

namespace Tests\PriceeIO\SyncPlugin\Unit\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PriceeIO\SyncPlugin\Helper\Params;
use PriceeIO\SyncPlugin\Service\WebhookPriceUpdater;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\CatalogPromotionInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Product\Model\ProductTranslationInterface;
use Sylius\Component\Product\Repository\ProductTranslationRepositoryInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class WebhookPriceUpdaterTest extends TestCase
{
    private ProductTranslationRepositoryInterface&MockObject $productTranslationRepository;

    private ChannelRepositoryInterface&MockObject $channelRepository;

    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->productTranslationRepository = $this->createMock(ProductTranslationRepositoryInterface::class);
        $this->channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    public function testUpdatesThePriceOfTheProductFoundByItsExtId(): void
    {
        $pricing = $this->createChannelPricing(1000, expectedPrice: 999);
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_EUR' => $pricing])], [$channel]), [$channel]);
        $this->entityManager->expects($this->once())->method('flush');

        $this->assertSame(WebhookPriceUpdater::UPDATED_PRICE, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testFindsAProductSyncedWithoutExtIdByItsSlug(): void
    {
        $pricing = $this->createChannelPricing(1000, expectedPrice: 999);
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $product = $this->createProduct([$this->createVariant(['WEB_EUR' => $pricing])], [$channel]);
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getTranslatable')->willReturn($product);
        $this->productTranslationRepository->method('findBy')->with(['slug' => 'awesome-t-shirt'])->willReturn([$translation]);
        $this->channelRepository->method('findAll')->willReturn([$channel]);

        $this->assertSame(WebhookPriceUpdater::UPDATED_PRICE, $this->createUpdater()->updateFromEntry($this->entry(['extId' => null])));
    }

    public function testUnknownExtIdIsSkippedWithoutFallingBackToTheSlug(): void
    {
        $this->entityManager->method('find')->willReturn(null);
        $this->productTranslationRepository->expects($this->never())->method('findBy');

        $this->assertSame(WebhookPriceUpdater::SKIPPED_UNKNOWN_PRODUCT, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testPriceChangedInTheShopSinceReadIsSkipped(): void
    {
        $pricing = $this->createChannelPricing(1500); // the merchant raised it after Pricee read 10.00
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_EUR' => $pricing])], [$channel]), [$channel]);

        $this->assertSame(WebhookPriceUpdater::SKIPPED_PRICE_CHANGED_IN_SHOP, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testEntryWithoutTheReadPriceIsSkipped(): void
    {
        $entry = $this->entry();
        unset($entry['currentPriceAmount']);

        $this->assertSame(WebhookPriceUpdater::SKIPPED_NO_READ_PRICE, $this->createUpdater()->updateFromEntry($entry));
    }

    public function testProductWithSeveralVariantsIsSkipped(): void
    {
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $variants = [
            $this->createVariant(['WEB_EUR' => $this->createChannelPricing(1000)]),
            $this->createVariant(['WEB_EUR' => $this->createChannelPricing(1200)]),
        ];
        $this->givenProduct(42, $this->createProduct($variants, [$channel]), [$channel]);

        $this->assertSame(WebhookPriceUpdater::SKIPPED_UNSUPPORTED_TYPE, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testOnlyTheChannelOfTheUrlIsUpdatedWhenSeveralShareTheCurrency(): void
    {
        $shopPricing = $this->createChannelPricing(1000, expectedPrice: 999);
        $b2bPricing = $this->createChannelPricing(1000);
        $shop = $this->createChannel('WEB_EUR', 'EUR', 'shop.example.com');
        $b2b = $this->createChannel('B2B_EUR', 'EUR', 'pro.example.com');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_EUR' => $shopPricing, 'B2B_EUR' => $b2bPricing])], [$shop, $b2b]), [$shop, $b2b]);

        $this->assertSame(WebhookPriceUpdater::UPDATED_PRICE, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testSeveralChannelsWithoutTheUrlHostAreSkipped(): void
    {
        $first = $this->createChannel('WEB_EUR', 'EUR');
        $second = $this->createChannel('B2B_EUR', 'EUR');
        $pricings = ['WEB_EUR' => $this->createChannelPricing(1000), 'B2B_EUR' => $this->createChannelPricing(1000)];
        $this->givenProduct(42, $this->createProduct([$this->createVariant($pricings)], [$first, $second]), [$first, $second]);

        $this->assertSame(WebhookPriceUpdater::SKIPPED_CHANNEL, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testACallSignedWithTheOwnSecretOfAChannelOnlyUpdatesThatChannel(): void
    {
        $frPricing = $this->createChannelPricing(1000, expectedPrice: 999);
        $bePricing = $this->createChannelPricing(1000);
        $fr = $this->createChannel('WEB_FR', 'EUR', 'shop.example.fr');
        $be = $this->createChannel('WEB_BE', 'EUR', 'shop.example.com');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_FR' => $frPricing, 'WEB_BE' => $bePricing])], [$fr, $be]), [$fr, $be]);
        $ownSecrets = ['WEB_FR' => ['webhook_secret' => 'fr_secret'], 'WEB_BE' => ['webhook_secret' => 'be_secret']];

        // The entry's URL is on the other channel's host: the secret decides, not the URL
        $this->assertSame(WebhookPriceUpdater::UPDATED_PRICE, $this->createUpdater($ownSecrets)->updateFromEntry($this->entry(), 'WEB_FR'));
    }

    public function testTheSharedSecretNeverUpdatesAChannelWithItsOwnSecret(): void
    {
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_EUR' => $this->createChannelPricing(1000)])], [$channel]), [$channel]);

        $this->assertSame(
            WebhookPriceUpdater::SKIPPED_CHANNEL,
            $this->createUpdater(['WEB_EUR' => ['webhook_secret' => 'own_secret']])->updateFromEntry($this->entry()),
        );
    }

    public function testChannelInAnotherCurrencyIsNotUpdated(): void
    {
        $channel = $this->createChannel('WEB_USD', 'USD');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_USD' => $this->createChannelPricing(1000)])], [$channel]), [$channel]);

        $this->assertSame(WebhookPriceUpdater::SKIPPED_CHANNEL, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testPriceWithACatalogPromotionIsSkipped(): void
    {
        $pricing = $this->createChannelPricing(1000, promotions: [$this->createMock(CatalogPromotionInterface::class)]);
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_EUR' => $pricing])], [$channel]), [$channel]);

        $this->assertSame(WebhookPriceUpdater::SKIPPED_PROMOTION, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testPriceWithAShownOriginalPriceIsSkipped(): void
    {
        $pricing = $this->createChannelPricing(1000, originalPrice: 1200);
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_EUR' => $pricing])], [$channel]), [$channel]);

        $this->assertSame(WebhookPriceUpdater::SKIPPED_PROMOTION, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testPriceBelowTheMinimumPriceIsSkipped(): void
    {
        $pricing = $this->createChannelPricing(1000, minimumPrice: 999 + 1);
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_EUR' => $pricing])], [$channel]), [$channel]);

        $this->assertSame(WebhookPriceUpdater::SKIPPED_BELOW_MINIMUM, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testDisabledProductIsSkipped(): void
    {
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_EUR' => $this->createChannelPricing(1000)])], [$channel], enabled: false), [$channel]);

        $this->assertSame(WebhookPriceUpdater::SKIPPED_DISABLED, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testSamePriceIsNotRewritten(): void
    {
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_EUR' => $this->createChannelPricing(1000)])], [$channel]), [$channel]);
        $this->entityManager->expects($this->never())->method('flush');

        $this->assertSame(WebhookPriceUpdater::UNCHANGED, $this->createUpdater()->updateFromEntry($this->entry(['priceAmount' => 10.0])));
    }

    public function testARetriedCallWhoseFirstAttemptWasAppliedIsUnchanged(): void
    {
        // The first attempt set 9.99 but its answer came too late: the retry still carries the 10.00 Pricee read
        $channel = $this->createChannel('WEB_EUR', 'EUR');
        $this->givenProduct(42, $this->createProduct([$this->createVariant(['WEB_EUR' => $this->createChannelPricing(999)])], [$channel]), [$channel]);
        $this->entityManager->expects($this->never())->method('flush');

        $this->assertSame(WebhookPriceUpdater::UNCHANGED, $this->createUpdater()->updateFromEntry($this->entry()));
    }

    public function testEntriesWithoutCurrencyOrWithAnInvalidPriceAreSkipped(): void
    {
        $updater = $this->createUpdater();

        $this->assertSame(WebhookPriceUpdater::SKIPPED_INVALID_ENTRY, $updater->updateFromEntry($this->entry(['priceCurrency' => null])));
        $this->assertSame(WebhookPriceUpdater::SKIPPED_INVALID_ENTRY, $updater->updateFromEntry($this->entry(['priceAmount' => -5])));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://shop.example.com/en_US/products/awesome-t-shirt',
            'extId' => '42',
            'priceAmount' => 9.99,
            'priceCurrency' => 'eur',
            // The price of the channel pricings below, as Pricee read it
            'currentPriceAmount' => 10.0,
        ], $overrides);
    }

    /**
     * @param array<ChannelInterface> $channels
     */
    private function givenProduct(int $id, ProductInterface $product, array $channels): void
    {
        $this->entityManager->method('find')->with(ProductInterface::class, $id)->willReturn($product);
        $this->channelRepository->method('findAll')->willReturn($channels);
    }

    /**
     * @param array<string, array<string, string>> $channels the pricee_io_sync.channels configuration
     */
    private function createUpdater(array $channels = []): WebhookPriceUpdater
    {
        return new WebhookPriceUpdater(
            $this->productTranslationRepository,
            $this->channelRepository,
            $this->entityManager,
            new NullLogger(),
            new Params(new ParameterBag(['priceeio.channels' => $channels])),
        );
    }

    /**
     * @param array<ProductVariantInterface> $variants
     * @param array<ChannelInterface> $channels the channels the product is sold on
     */
    private function createProduct(array $variants, array $channels, bool $enabled = true): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getCode')->willReturn('T_SHIRT');
        $product->method('isEnabled')->willReturn($enabled);
        $product->method('getEnabledVariants')->willReturn(new ArrayCollection($variants));
        $product->method('hasChannel')->willReturnCallback(static fn (ChannelInterface $channel): bool => in_array($channel, $channels, true));

        return $product;
    }

    /**
     * @param array<string, ChannelPricingInterface> $channelPricings by channel code
     */
    private function createVariant(array $channelPricings): ProductVariantInterface
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getChannelPricingForChannel')->willReturnCallback(
            static fn (ChannelInterface $channel): ?ChannelPricingInterface => $channelPricings[$channel->getCode()] ?? null,
        );

        return $variant;
    }

    private function createChannel(string $code, string $currencyCode, ?string $hostname = null): ChannelInterface
    {
        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getCode')->willReturn($currencyCode);

        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);
        $channel->method('getBaseCurrency')->willReturn($currency);
        $channel->method('getHostname')->willReturn($hostname);

        return $channel;
    }

    /**
     * @param array<CatalogPromotionInterface> $promotions
     */
    private function createChannelPricing(
        int $price,
        ?int $expectedPrice = null,
        ?int $originalPrice = null,
        int $minimumPrice = 0,
        array $promotions = [],
    ): ChannelPricingInterface {
        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->method('getPrice')->willReturn($price);
        $channelPricing->method('getOriginalPrice')->willReturn($originalPrice);
        $channelPricing->method('getMinimumPrice')->willReturn($minimumPrice);
        $channelPricing->method('getAppliedPromotions')->willReturn(new ArrayCollection($promotions));
        if (null === $expectedPrice) {
            $channelPricing->expects($this->never())->method('setPrice');
        } else {
            $channelPricing->expects($this->once())->method('setPrice')->with($expectedPrice);
        }

        return $channelPricing;
    }
}
