<?php

declare(strict_types=1);

namespace Tests\PriceeIO\SyncPlugin\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PriceeIO\SyncPlugin\Controller\WebhookController;
use PriceeIO\SyncPlugin\Helper\Params;
use PriceeIO\SyncPlugin\Service\WebhookPriceUpdater;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Product\Repository\ProductTranslationRepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class WebhookControllerTest extends TestCase
{
    private const SECRET = 'test_webhook_secret';

    private ArrayAdapter $cache;

    private ProductTranslationRepositoryInterface&MockObject $productTranslationRepository;

    private ChannelRepositoryInterface&MockObject $channelRepository;

    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->productTranslationRepository = $this->createMock(ProductTranslationRepositoryInterface::class);
        $this->productTranslationRepository->method('findBy')->willReturn([]);
        $this->channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $this->channelRepository->method('findAll')->willReturn([]);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    public function testReturns401WhenWebhookIsNotConfigured(): void
    {
        $response = $this->call([], enabled: false, secret: '');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testReturns400WhenSignatureIsMissing(): void
    {
        $response = $this->call([], signature: '');

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testReturns401WhenSignatureIsInvalid(): void
    {
        $response = $this->call([], signature: 'invalid_signature');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testReturns400WhenPayloadIsNotValidJson(): void
    {
        $body = 'not-json';
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $response = $this->call([], body: $body, signature: $signature);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testReturns401WhenTimestampOrNonceIsMissing(): void
    {
        $entries = [['url' => 'https://shop.example.com/products/t-shirt', 'bestPriceAmount' => 9.99]];

        $response = $this->call($entries);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testReturns401WhenTimestampIsStale(): void
    {
        $entries = [$this->entry(timestamp: time() - 3600)];

        $response = $this->call($entries);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testReturns401WhenTheSameNonceIsReplayed(): void
    {
        $entries = [$this->entry()];

        $first = $this->call($entries);
        $second = $this->call($entries);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(401, $second->getStatusCode());
    }

    public function testProcessesAValidPayloadWithABatchSharedNonce(): void
    {
        // The sender signs a whole batch with a single nonce shared by every entry
        $entries = [$this->entry(), $this->entry()];

        $response = $this->call($entries);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true, 'updated' => 0], json_decode($response->getContent(), true));
    }

    /**
     * @param array<mixed> $entries
     */
    private function call(
        array $entries,
        ?string $body = null,
        ?string $signature = null,
        bool $enabled = true,
        string $secret = self::SECRET,
    ): JsonResponse {
        $body ??= json_encode($entries, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $signature ??= hash_hmac('sha256', $body, $secret);

        $request = new Request([], [], [], [], [], [], $body);
        $request->headers->set('X-Signature', $signature);

        $controller = new WebhookController(
            $this->createParams($enabled, $secret),
            $this->createPriceUpdater(),
            $this->cache,
            new NullLogger(),
        );

        return ($controller)($request);
    }

    /**
     * @return array{url: string, bestPriceAmount: float, timestamp: int, nonce: string}
     */
    private function entry(?int $timestamp = null, string $nonce = 'nonce'): array
    {
        return [
            'url' => 'https://shop.example.com/products/t-shirt',
            'bestPriceAmount' => 9.99,
            'timestamp' => $timestamp ?? time(),
            'nonce' => $nonce,
        ];
    }

    private function createParams(bool $enabled, string $secret): Params
    {
        $bag = $this->createMock(ParameterBagInterface::class);
        $bag->method('get')->willReturnMap([
            ['priceeio.webhook_enabled', $enabled],
            ['priceeio.webhook_secret', $secret],
        ]);

        return new Params($bag);
    }

    private function createPriceUpdater(): WebhookPriceUpdater
    {
        return new WebhookPriceUpdater(
            $this->productTranslationRepository,
            $this->channelRepository,
            $this->entityManager,
            new NullLogger(),
        );
    }
}
