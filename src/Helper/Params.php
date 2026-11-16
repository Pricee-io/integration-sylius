<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Helper;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The plugin settings. Each channel is one website on Pricee.io, with its own
 * API key and webhook secret: they are set per channel code in the
 * `pricee_io_sync.channels` configuration, and a channel left out (or a value
 * left empty) uses the PRICEEIO_* environment variables.
 */
class Params
{
    private ParameterBagInterface $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    /**
     * The API key of a channel: its own when both its client ID and API key
     * are set, the environment variables otherwise.
     *
     * @return array{clientId: string, apiKey: string}
     */
    public function getCredentials(?string $channelCode = null): array
    {
        $channel = null !== $channelCode ? ($this->getChannels()[$channelCode] ?? []) : [];
        if ('' !== ($channel['client_id'] ?? '') && '' !== ($channel['api_key'] ?? '')) {
            return ['clientId' => $channel['client_id'], 'apiKey' => $channel['api_key']];
        }

        return [
            'clientId' => $this->getString('priceeio.client_id'),
            'apiKey' => $this->getString('priceeio.api_key'),
        ];
    }

    /**
     * Whether the webhook is enabled, and the secret of the channels without
     * their own one.
     *
     * @return array{enabled: bool, secret: string}
     */
    public function getWebhookConfig(): array
    {
        try {
            $enabled = (bool) $this->params->get('priceeio.webhook_enabled');
        } catch (\Exception) {
            $enabled = false;
        }

        return [
            'enabled' => $enabled,
            'secret' => $this->getString('priceeio.webhook_secret'),
        ];
    }

    /**
     * The channels with their own webhook secret.
     *
     * @return array<string, string> webhook secret by channel code
     */
    public function getChannelWebhookSecrets(): array
    {
        $secrets = [];
        foreach ($this->getChannels() as $code => $channel) {
            if ('' !== ($channel['webhook_secret'] ?? '')) {
                $secrets[$code] = $channel['webhook_secret'];
            }
        }

        return $secrets;
    }

    /**
     * @return array<string, array{client_id?: string, api_key?: string, webhook_secret?: string}>
     */
    private function getChannels(): array
    {
        try {
            $channels = $this->params->get('priceeio.channels');
        } catch (\Exception) {
            return [];
        }

        $result = [];
        foreach (is_array($channels) ? $channels : [] as $code => $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $values = [];
            foreach (['client_id', 'api_key', 'webhook_secret'] as $key) {
                if (is_string($channel[$key] ?? null)) {
                    $values[$key] = $channel[$key];
                }
            }
            $result[(string) $code] = $values;
        }

        return $result;
    }

    private function getString(string $name): string
    {
        try {
            $value = $this->params->get($name);
        } catch (\Exception) {
            return '';
        }

        return is_string($value) ? $value : '';
    }
}
