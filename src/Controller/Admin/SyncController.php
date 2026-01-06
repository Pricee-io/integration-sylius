<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Controller\Admin;

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
        // private SyncService $syncService
    ) {
    }

    /** GET: show form */
    public function index(): Response
    {
        $categories = $this->taxonRepository->findBy(
            ['enabled' => true],
            ['position' => 'ASC']
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
            $request->request->get('_token')
        )) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 400);
        }

        $categoryCodes = $request->request->all('categories');

        if (empty($categoryCodes)) {
            return new JsonResponse([
                'error' => 'No categories selected',
            ], 400);
        }

        // 🔹 Resolve products
        $qb = $this->productRepository->createQueryBuilder('p')
            ->join('p.productTaxons', 'pt')
            ->join('pt.taxon', 't')
            ->andWhere('t.code IN (:codes)')
            ->andWhere('p.enabled = true')
            ->setParameter('codes', $categoryCodes);

        // 🔹 Plan limit
        $planLimit = 100;

        $products = $qb
            ->setMaxResults($planLimit)
            ->getQuery()
            ->getResult();

        // 🔹 Sync (sync service / messenger)
        // $this->syncService->syncProducts($products);

        return new JsonResponse([
            'success' => true,
            'synced' => count($products),
        ]);
    }
}
