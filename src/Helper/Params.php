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
            return [
                'clientId' => $this->params->get('priceeio.client_id'),
                'apiKey' => $this->params->get('priceeio.api_key'),
            ];
        } catch (\Exception $e) {
            return [
                'clientId' => '',
                'apiKey' => '',
            ];
        }
    }
}
