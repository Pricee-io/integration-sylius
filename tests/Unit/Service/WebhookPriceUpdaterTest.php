<?php

declare(strict_types=1);

namespace Tests\PriceeIO\SyncPlugin\Unit\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PriceeIO\SyncPlugin\Service\WebhookPriceUpdater;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Product\Model\ProductTranslationInterface;
use Sylius\Component\Product\Repository\ProductTranslationRepositoryInterface;

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

    public function testUpdatesChannelPricingInMatchingCurrency(): void
    {
        $eurPricing = $this->createChannelPricing(999);
        $usdPricing = $this->createChannelPricing(999, times: 0);
        $channels = [
            $this->createChannel('WEB_EUR', 'EUR'),
            $this->createChannel('WEB_USD', 'USD'),
        ];

        $translation = $this->createTranslationForProduct(
            $this->createProductWithVariants([$this->createVariant('T_SHIRT_VARIANT', ['WEB_EUR' => $eurPricing, 'WEB_USD' => $usdPricing])]),
        );

        $this->productTranslationRepository
            ->method('findBy')
            ->with(['slug' => 'awesome-t-shirt'])
            ->willReturn([$translation]);
        $this->channelRepository->method('findAll')->willReturn($channels);
        $this->entityManager->expects($this->once())->method('flush');

        $updater = $this->createUpdater();

        $updatedCount = $updater->updateFromEntry([
            'url' => 'https://shop.example.com/en_US/products/awesome-t-shirt',
            'bestPriceAmount' => 9.99,
            'bestPriceCurrency' => 'eur',
        ]);

        $this->assertSame(1, $updatedCount);
    }

    public function testSkipsEntriesWithoutCurrency(): void
    {
        $updater = $this->createUpdater();

        $updatedCount = $updater->updateFromEntry([
            'url' => 'https://shop.example.com/products/awesome-t-shirt',
            'bestPriceAmount' => 5.0,
        ]);

        $this->assertSame(0, $updatedCount);
        $this->entityManager->expects($this->never())->method('flush');
    }

    public function testSkipsChannelsWithoutBaseCurrency(): void
    {
        $eurPricing = $this->createChannelPricing(999);
        $unknownPricing = $this->createChannelPricing(999, times: 0);
        $channels = [
            $this->createChannel('WEB_EUR', 'EUR'),
            $this->createChannel('WEB_UNKNOWN', null),
        ];

        $translation = $this->createTranslationForProduct(
            $this->createProductWithVariants([$this->createVariant('T_SHIRT_VARIANT', ['WEB_EUR' => $eurPricing, 'WEB_UNKNOWN' => $unknownPricing])]),
        );

        $this->productTranslationRepository
            ->method('findBy')
            ->with(['slug' => 'awesome-t-shirt'])
            ->willReturn([$translation]);
        $this->channelRepository->method('findAll')->willReturn($channels);
        $this->entityManager->expects($this->once())->method('flush');

        $updater = $this->createUpdater();

        $updatedCount = $updater->updateFromEntry([
            'url' => 'https://shop.example.com/products/awesome-t-shirt',
            'bestPriceAmount' => 9.99,
            'bestPriceCurrency' => 'EUR',
        ]);

        $this->assertSame(1, $updatedCount);
    }

    public function testSkipsEntriesWithInvalidPrice(): void
    {
        $updater = $this->createUpdater();

        $updatedCount = $updater->updateFromEntry([
            'url' => 'https://shop.example.com/products/awesome-t-shirt',
            'bestPriceAmount' => -5,
        ]);

        $this->assertSame(0, $updatedCount);
        $this->entityManager->expects($this->never())->method('flush');
    }

    private function createUpdater(): WebhookPriceUpdater
    {
        return new WebhookPriceUpdater(
            $this->productTranslationRepository,
            $this->channelRepository,
            $this->entityManager,
            new NullLogger(),
        );
    }

    /**
     * @return ProductTranslationInterface&MockObject
     */
    private function createTranslationForProduct(ProductInterface $product): ProductTranslationInterface
    {
        $translation = $this->createMock(ProductTranslationInterface::class);
        $translation->method('getTranslatable')->willReturn($product);

        return $translation;
    }

    /**
     * @return ProductInterface&MockObject
     */
    private function createProductWithVariants(array $variants): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getCode')->willReturn('T_SHIRT');
        $product->method('getEnabledVariants')->willReturn(new ArrayCollection($variants));

        return $product;
    }

    /**
     * @return ProductVariantInterface&MockObject
     */
    private function createVariant(string $code, array $channelPricings): ProductVariantInterface
    {
        $variant = $this->createMock(ProductVariantInterface::class);
        $variant->method('getChannelPricingForChannel')->willReturnCallback(
            function (ChannelInterface $channel) use ($channelPricings): ?ChannelPricingInterface {
                return $channelPricings[$channel->getCode()] ?? null;
            },
        );

        return $variant;
    }

    /**
     * @return ChannelInterface&MockObject
     */
    private function createChannel(string $code, ?string $currencyCode): ChannelInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);

        if (null !== $currencyCode) {
            $currency = $this->createMock(CurrencyInterface::class);
            $currency->method('getCode')->willReturn($currencyCode);
            $channel->method('getBaseCurrency')->willReturn($currency);
        } else {
            $channel->method('getBaseCurrency')->willReturn(null);
        }

        return $channel;
    }

    /**
     * @return ChannelPricingInterface&MockObject
     */
    private function createChannelPricing(int $expectedPrice, int $times = 1): ChannelPricingInterface
    {
        $channelPricing = $this->createMock(ChannelPricingInterface::class);
        $channelPricing->expects($this->exactly($times))->method('setPrice')->with($expectedPrice);

        return $channelPricing;
    }
}
