<?php

declare(strict_types=1);

namespace Tests\PriceeIO\SyncPlugin\Unit\Helper;

use PHPUnit\Framework\TestCase;
use PriceeIO\SyncPlugin\Helper\Params;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * Each channel is one website on Pricee.io: its own settings win, the
 * environment variables apply to the channels without them.
 */
final class ParamsTest extends TestCase
{
    public function testAChannelUsesItsOwnApiKeyWhenBothValuesAreSet(): void
    {
        $params = $this->createParams([
            'WEB_FR' => ['client_id' => 'fr_client', 'api_key' => 'fr_key'],
            'WEB_DE' => ['client_id' => 'de_client', 'api_key' => ''], // incomplete: never mixed with the default key
        ]);

        $this->assertSame(['clientId' => 'fr_client', 'apiKey' => 'fr_key'], $params->getCredentials('WEB_FR'));
        $this->assertSame(['clientId' => 'env_client', 'apiKey' => 'env_key'], $params->getCredentials('WEB_DE'));
        $this->assertSame(['clientId' => 'env_client', 'apiKey' => 'env_key'], $params->getCredentials('WEB_UNLISTED'));
        $this->assertSame(['clientId' => 'env_client', 'apiKey' => 'env_key'], $params->getCredentials());
    }

    public function testOnlyTheChannelsWithAWebhookSecretHaveTheirOwn(): void
    {
        $params = $this->createParams([
            'WEB_FR' => ['webhook_secret' => 'fr_secret'],
            'WEB_DE' => ['client_id' => 'de_client', 'api_key' => 'de_key', 'webhook_secret' => null],
        ]);

        $this->assertSame(['WEB_FR' => 'fr_secret'], $params->getChannelWebhookSecrets());
        $this->assertSame(['enabled' => true, 'secret' => 'env_secret'], $params->getWebhookConfig());
    }

    public function testWorksWithoutAnyChannelConfiguration(): void
    {
        $params = new Params(new ParameterBag(['priceeio.client_id' => 'env_client', 'priceeio.api_key' => 'env_key']));

        $this->assertSame(['clientId' => 'env_client', 'apiKey' => 'env_key'], $params->getCredentials('WEB_FR'));
        $this->assertSame([], $params->getChannelWebhookSecrets());
    }

    /**
     * @param array<string, array<string, ?string>> $channels
     */
    private function createParams(array $channels): Params
    {
        return new Params(new ParameterBag([
            'priceeio.client_id' => 'env_client',
            'priceeio.api_key' => 'env_key',
            'priceeio.webhook_enabled' => true,
            'priceeio.webhook_secret' => 'env_secret',
            'priceeio.channels' => $channels,
        ]));
    }
}
