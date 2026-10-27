<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Controller;

use PriceeIO\SyncPlugin\Helper\Params;
use PriceeIO\SyncPlugin\Service\WebhookPriceUpdater;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives price updates pushed by Pricee.io.
 *
 * The payload is a JSON array of entries signed with HMAC-SHA256 over the raw
 * body using the webhook secret (`X-Signature` header). Each entry carries a
 * `timestamp` and a `nonce` for replay protection, as sent by the Pricee.io app.
 */
final class WebhookController
{
    private const MAX_AGE = 300;

    private const NONCE_TTL = 600;

    public function __construct(
        private Params $params,
        private WebhookPriceUpdater $priceUpdater,
        private CacheItemPoolInterface $cacheApp,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $webhookConfig = $this->params->getWebhookConfig();
        if (!$webhookConfig['enabled'] || '' === $webhookConfig['secret']) {
            return $this->failure('Webhook not configured', 401);
        }

        $body = $request->getContent();
        $signature = (string) $request->headers->get('X-Signature', '');

        if ('' === $body || '' === $signature) {
            return $this->failure('Missing body or signature', 400);
        }

        if (!hash_equals(hash_hmac('sha256', $body, $webhookConfig['secret']), $signature)) {
            return $this->failure('Invalid signature', 401);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $this->failure('Invalid JSON payload', 400);
        }

        $rejection = $this->validateEntries($data);
        if (null !== $rejection) {
            return $this->failure($rejection, 401);
        }

        $updatedCount = 0;
        foreach ($data as $entry) {
            if (is_array($entry)) {
                $updatedCount += $this->priceUpdater->updateFromEntry($entry);
            }
        }

        return new JsonResponse(['success' => true, 'updated' => $updatedCount]);
    }

    /**
     * Replay protection: each entry must carry a fresh timestamp and a nonce
     * that has not been used within the accepted window. The sender uses a
     * single nonce for the whole batch, so nonces are checked once per payload,
     * not once per entry. Returns null when valid, or the rejection reason
     * otherwise.
     *
     * @param array<mixed> $data
     */
    private function validateEntries(array $data): ?string
    {
        $seenNonces = [];

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                return 'Invalid payload entry';
            }

            $timestamp = $entry['timestamp'] ?? null;
            $nonce = $entry['nonce'] ?? null;

            if (!is_numeric($timestamp) || !is_string($nonce) || '' === $nonce) {
                return 'Missing timestamp or nonce';
            }

            if (abs(time() - (int) $timestamp) > self::MAX_AGE) {
                return 'Expired webhook payload';
            }

            $nonceKey = 'priceeio_nonce_' . hash('sha256', $nonce);
            if (isset($seenNonces[$nonceKey])) {
                continue;
            }
            $seenNonces[$nonceKey] = true;

            $item = $this->cacheApp->getItem($nonceKey);
            if ($item->isHit()) {
                $this->logger->info('Pricee webhook: replayed request rejected.', ['nonce_hash' => $nonceKey]);

                return 'Replayed webhook payload';
            }

            $item->set(true);
            $item->expiresAfter(self::NONCE_TTL);
            $this->cacheApp->save($item);
        }

        return null;
    }

    private function failure(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => $message], $status);
    }
}
