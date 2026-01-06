<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: 'sylius.menu.admin.main', method: 'addPriceeIOMenuItems')]
final class PriceeIOMenuListener
{
	public function addPriceeIOMenuItems(MenuBuilderEvent $event): void
	{
    	$menu = $event->getMenu();

    	$newSubmenu = $menu
        	->addChild('app_admin_priceeio')
        	->setLabel('Pricee.io')
        	->setLabelAttribute('icon', 'tabler:coin-euro')
    	;

    	$newSubmenu
        	// ->addChild('app_admin_priceeio_sync', ['route' => 'app_admin_priceeio_sync'])
            ->addChild('app_admin_priceeio_sync')
        	->setLabel('Synchronisation')
    	;
	}
}
