<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use PriceeIO\SyncPlugin\Helper\CategoryProductCounter;
use PriceeIO\SyncPlugin\Helper\Params;
use PriceeIO\SyncPlugin\Service\SyncService;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SyncController extends AbstractController
{
    /** Products synced per request: the page sends one request per batch, so each stays short whatever the selection */
    public const BATCH_SIZE = 50;

    /**
     * @param TaxonRepositoryInterface<TaxonInterface> $taxonRepository
     */
    public function __construct(
        private TaxonRepositoryInterface $taxonRepository,
        private EntityManagerInterface $entityManager,
        private SyncService $syncService,
        private Params $params,
        private CategoryProductCounter $categoryProductCounter,
    ) {
    }

    /** GET: show form */
    public function index(): Response
    {
        $credentialsSet = true;

        $credentials = $this->params->getCredentials();
        if (empty($credentials['clientId']) || empty($credentials['apiKey'])) {
            $credentialsSet = false;
        }

        $categories = $this->taxonRepository->findBy(
            ['enabled' => true],
            ['position' => 'ASC'],
        );

        $categoriesWithCount = [];
        foreach ($categories as $category) {
            $categoriesWithCount[] = [
                'code' => $category->getCode(),
                'name' => $category->getName(),
                'productCount' => $this->categoryProductCounter->countByTaxonCode((string) $category->getCode()),
            ];
        }

        return $this->render('@PriceeIOSyncPlugin/admin/sync/index.html.twig', [
            'credentialsSet' => $credentialsSet,
            'categories' => $categoriesWithCount,
        ]);
    }

    /** POST: run sync (AJAX) */
    public function run(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(
            'priceeio_categories',
            (string) $request->request->get('_token'),
        )) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 400);
        }

        $categoryCodes = $request->request->all('categories');

        if (empty($categoryCodes)) {
            return new JsonResponse([
                'error' => 'No categories selected.',
            ], 400);
        }

        // Batch cursor: the sync goes on after the last product of the previous batch
        $afterId = max(0, (int) $request->request->get('after_id'));

        // Resolve products from selected categories, each once, in ID order
        $idsQb = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT p.id')
            ->from('Sylius\\Component\\Core\\Model\\Product', 'p')
            ->join('p.productTaxons', 'pt')
            ->join('pt.taxon', 't')
            ->andWhere('t.code IN (:codes)')
            ->andWhere('p.enabled = true')
            ->orderBy('p.id', 'ASC')
            ->setParameter('codes', $categoryCodes);
        $allIds = array_map('intval', array_column($idsQb->getQuery()->getScalarResult(), 'id'));
        $remainingIds = array_values(array_filter($allIds, static fn (int $id): bool => $id > $afterId));
        $productIds = array_slice($remainingIds, 0, self::BATCH_SIZE);

        if ([] === $productIds) {
            return new JsonResponse(['success' => true, 'synced' => 0, 'failed' => 0, 'last_id' => $afterId, 'done' => true, 'total' => count($allIds)]);
        }

        $query = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from('Sylius\\Component\\Core\\Model\\Product', 'p')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', $productIds)
            ->orderBy('p.id', 'ASC')
            ->getQuery();

        // Perform sync via your service
        $websiteUrl = $request->getSchemeAndHttpHost();

        try {
            $productsCount = $this->syncService->syncProducts($websiteUrl, $query);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage() ?: 'Unknown error during sync.',
            ], 500);
        }

        return new JsonResponse([
            'success' => true,
            'synced' => $productsCount,
            'failed' => count($productIds) - $productsCount,
            'last_id' => end($productIds),
            'done' => count($remainingIds) <= self::BATCH_SIZE,
            'total' => count($allIds),
        ]);
    }
}
