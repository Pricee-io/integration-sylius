<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Controller\Admin;

use PriceeIO\SyncPlugin\Service\SyncService;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SyncController extends AbstractController
{
    public function __construct(
        private TaxonRepositoryInterface $taxonRepository,
        private ProductRepositoryInterface $productRepository,
        private SyncService $syncService,
    ) {
    }

    /** GET: show form */
    public function index(): Response
    {
        $categories = $this->taxonRepository->findBy(
            ['enabled' => true],
            ['position' => 'ASC'],
        );

        return $this->render('@PriceeIOSyncPlugin/admin/sync/index.html.twig', [
            'categories' => $categories,
        ]);
    }

    /** POST: run sync (AJAX) */
    public function run(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(
            'priceeio_categories',
            $request->request->get('_token'),
        )) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 400);
        }

        $categoryCodes = $request->request->all('categories');
        $clientId = trim($request->request->get('clientId', ''));
        $key = trim($request->request->get('key', ''));

        if (empty($categoryCodes)) {
            return new JsonResponse([
                'error' => 'No categories selected.',
            ], 400);
        }

        if (!$clientId || !$key) {
            return new JsonResponse([
                'error' => 'Client ID and API Key are required.',
            ], 400);
        }

        // 🔹 Resolve products from selected categories
        $qb = $this->productRepository->createQueryBuilder('p')
            ->join('p.productTaxons', 'pt')
            ->join('pt.taxon', 't')
            ->andWhere('t.code IN (:codes)')
            ->andWhere('p.enabled = true')
            ->setParameter('codes', $categoryCodes);

        $planLimit = 100; // TODO: get from plan service
        $products = $qb->setMaxResults($planLimit)->getQuery()->getResult();

        // 🔹 Perform sync via your service
        try {
            $this->syncService->syncProducts($products, $clientId, $key);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage() ?: 'Unknown error during sync.',
            ], 500);
        }

        return new JsonResponse([
            'success' => true,
            'synced' => count($products),
        ]);
    }
}
