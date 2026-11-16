<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Controller;

use Composer\InstalledVersions;
use PriceeIO\SyncPlugin\Helper\Params;
use PriceeIO\SyncPlugin\Service\WebhookPriceUpdater;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\CoreBundle\SyliusCoreBundle;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives price updates pushed by Pricee.io.
 *
 * The payload is a JSON array of entries signed with HMAC-SHA256 over the raw
 * body using the webhook secret (`X-Signature` header). Each entry carries a
 * `timestamp` and a `nonce` for replay protection, as sent by the Pricee.io app.
 *
 * Each channel is one website on Pricee.io, which signs with that website's
 * secret: the secret that matches tells which channel the call may update.
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
        // Where the lock that makes checking and consuming nonces atomic lives
        private string $lockDirectory = '',
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $webhookConfig = $this->params->getWebhookConfig();
        $channelSecrets = $this->params->getChannelWebhookSecrets();
        if (!$webhookConfig['enabled'] || ('' === $webhookConfig['secret'] && [] === $channelSecrets)) {
            return $this->failure('Webhook not configured', 401);
        }

        $body = $request->getContent();
        $signature = (string) $request->headers->get('X-Signature', '');

        if ('' === $body || '' === $signature) {
            return $this->failure('Missing body or signature', 400);
        }

        $scope = $this->signatureScope($body, $signature, $webhookConfig['secret'], $channelSecrets);
        if (false === $scope) {
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

        // A large call writes many prices
        @set_time_limit(300);

        $results = [];
        $outcomes = [];
        foreach ($data as $entry) {
            // validateEntries() refused the call if any entry was not an array
            $outcome = is_array($entry) ? $this->priceUpdater->updateFromEntry($entry, $scope) : WebhookPriceUpdater::SKIPPED_INVALID_ENTRY;
            $results[] = $outcome;
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        return $this->answer([
            'success' => true,
            'updated' => $outcomes[WebhookPriceUpdater::UPDATED_PRICE] ?? 0,
            // How many entries were updated, unchanged or skipped, and why
            'outcomes' => $outcomes,
            // The outcome of each entry, in payload order
            'results' => $results,
        ], accepted: true);
    }

    /**
     * The channel the call may update: the code of the channel whose own
     * secret signed it, null when signed with the shared secret (the channels
     * without their own one), false when no secret matches.
     *
     * @param array<string, string> $channelSecrets
     */
    private function signatureScope(string $body, string $signature, string $sharedSecret, array $channelSecrets): string|false|null
    {
        foreach ($channelSecrets as $channelCode => $secret) {
            if (hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
                // A numeric channel code is an integer array key
                return (string) $channelCode;
            }
        }

        if ('' !== $sharedSecret && hash_equals(hash_hmac('sha256', $body, $sharedSecret), $signature)) {
            return null;
        }

        return false;
    }

    /**
     * Replay protection: each entry must carry a fresh timestamp and a nonce
     * that has not been used within the accepted window. The sender uses a
     * single nonce for the whole batch, so nonces are checked once per payload,
     * not once per entry, and only consumed once the whole call is accepted.
     * Returns null when valid, or the rejection reason otherwise.
     *
     * @param array<mixed> $data
     */
    private function validateEntries(array $data): ?string
    {
        $nonceKeys = [];

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

            $nonceKeys['priceeio_nonce_' . hash('sha256', $nonce)] = true;
        }

        // Locked, so of two calls carrying the same nonce at the same time only one gets through
        $lock = $this->lockNonces();

        try {
            $items = [];
            foreach (array_keys($nonceKeys) as $nonceKey) {
                $item = $this->cacheApp->getItem($nonceKey);
                if ($item->isHit()) {
                    $this->logger->info('Pricee webhook: replayed request rejected.', ['nonce_hash' => $nonceKey]);

                    return 'Replayed webhook payload';
                }
                $items[] = $item;
            }

            foreach ($items as $item) {
                $item->set(true);
                $item->expiresAfter(self::NONCE_TTL);
                $this->cacheApp->save($item);
            }

            return null;
        } finally {
            if (null !== $lock) {
                flock($lock, \LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * An exclusive lock on a file of the server, held while nonces are
     * checked and consumed. Without a writable directory the check still
     * runs, unlocked, as before.
     *
     * @return resource|null
     */
    private function lockNonces()
    {
        $directory = '' !== $this->lockDirectory ? $this->lockDirectory : sys_get_temp_dir();
        $handle = @fopen($directory . '/priceeio_webhook_nonces.lock', 'c');
        if (false === $handle) {
            return null;
        }
        if (!flock($handle, \LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    private function failure(string $message, int $status): JsonResponse
    {
        return $this->answer(['success' => false, 'error' => $message], $status);
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function answer(array $answer, int $status = 200, bool $accepted = false): JsonResponse
    {
        // Which plugin and version answered, refused calls included: shown in Pricee's webhook history.
        // The Sylius version and the runtime (PHP) only to a call signed by Pricee: anyone can read a refused call's answer.
        $answer['plugin'] = ['platform' => 'sylius', 'version' => self::version()]
            + ($accepted ? ['platform_version' => SyliusCoreBundle::VERSION, 'runtime' => 'php', 'runtime_version' => \PHP_VERSION] : []);

        return new JsonResponse($answer, $status);
    }

    // The installed version of the plugin (the release tag), null when Composer does not know it
    public static function version(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('priceeio/integration-sylius');
        } catch (\OutOfBoundsException) {
            return null;
        }
    }
}
