<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Helper;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class Params
{
    private ParameterBagInterface $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    /**
     * @return array{clientId: string, apiKey: string}
     */
    public function getCredentials(): array
    {
        try {
            $clientId = $this->params->get('priceeio.client_id');
            $apiKey = $this->params->get('priceeio.api_key');

            return [
                'clientId' => is_string($clientId) ? $clientId : '',
                'apiKey' => is_string($apiKey) ? $apiKey : '',
            ];
        } catch (\Exception $e) {
            return [
                'clientId' => '',
                'apiKey' => '',
            ];
        }
    }

    /**
     * @return array{enabled: bool, secret: string}
     */
    public function getWebhookConfig(): array
    {
        $enabled = false;
        $secret = '';

        try {
            $enabled = (bool) $this->params->get('priceeio.webhook_enabled');
            $secret = $this->params->get('priceeio.webhook_secret');
        } catch (\Exception $e) {
            return [
                'enabled' => false,
                'secret' => '',
            ];
        }

        return [
            'enabled' => $enabled,
            'secret' => is_string($secret) ? $secret : '',
        ];
    }
}
