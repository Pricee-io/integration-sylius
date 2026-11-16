<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('pricee_io_sync');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('channels')
                    ->info('Pricee.io API key and webhook secret of each channel, by channel code: a channel left out, or a value left empty, uses the PRICEEIO_* environment variables')
                    ->useAttributeAsKey('code')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('client_id')->defaultNull()->end()
                            ->scalarNode('api_key')->defaultNull()->end()
                            ->scalarNode('webhook_secret')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
