<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class SyncController extends AbstractController
{
    public function index(): Response
    {
        return $this->render('@PriceeIOSyncPlugin/admin/sync/index.html.twig');
    }
}
