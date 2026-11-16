<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use PriceeIO\SyncPlugin\Helper\CategoryProductCounter;
use PriceeIO\SyncPlugin\Helper\Params;
use PriceeIO\SyncPlugin\Service\SyncService;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A sync targets exactly one channel, chosen on the page: each channel is one
 * website on Pricee.io, with its own API key.
 */
final class SyncController extends AbstractController
{
    /** Products synced per request: the page sends one request per batch, so each stays short whatever the selection */
    public const BATCH_SIZE = 50;

    /** Checked here too, not only by the admin firewall: the sync uses the shop's API keys */
    private const ADMIN_ROLE = 'ROLE_ADMINISTRATION_ACCESS';

    /**
     * @param TaxonRepositoryInterface<TaxonInterface> $taxonRepository
     * @param ChannelRepositoryInterface<\Sylius\Component\Channel\Model\ChannelInterface> $channelRepository
     */
    public function __construct(
        private TaxonRepositoryInterface $taxonRepository,
        private ChannelRepositoryInterface $channelRepository,
        private EntityManagerInterface $entityManager,
        private SyncService $syncService,
        private Params $params,
        private CategoryProductCounter $categoryProductCounter,
    ) {
    }

    /** GET: show form */
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(self::ADMIN_ROLE);

        $channels = $this->enabledChannels();
        $channel = $this->selectedChannel($channels, $request);

        $credentials = $this->params->getCredentials($channel?->getCode());
        $credentialsSet = '' !== $credentials['clientId'] && '' !== $credentials['apiKey'];

        $categoriesWithCount = [];
        if (null !== $channel) {
            $categories = $this->taxonRepository->findBy(
                ['enabled' => true],
                ['position' => 'ASC'],
            );
            foreach ($categories as $category) {
                $categoriesWithCount[] = [
                    'code' => $category->getCode(),
                    'name' => $category->getName(),
                    'productCount' => $this->categoryProductCounter->countByTaxonCode((string) $category->getCode(), $channel),
                ];
            }
        }

        return $this->render('@PriceeIOSyncPlugin/admin/sync/index.html.twig', [
            'credentialsSet' => $credentialsSet,
            'categories' => $categoriesWithCount,
            'channels' => array_map(static fn (ChannelInterface $c): array => [
                'code' => $c->getCode(),
                'name' => $c->getName(),
                'url' => SyncService::channelUrl($c, $request->getScheme(), $request->getHttpHost()),
            ], $channels),
            'channelCode' => $channel?->getCode(),
        ]);
    }

    /** POST: run sync (AJAX) */
    public function run(Request $request): JsonResponse
    {
        if (!$this->isGranted(self::ADMIN_ROLE)) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        if (!$this->isCsrfTokenValid(
            'priceeio_categories',
            (string) $request->request->get('_token'),
        )) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 400);
        }

        // Never fall back to another channel than the one chosen on the page
        $channel = $this->channelRepository->findOneByCode((string) $request->request->get('channel'));
        if (!$channel instanceof ChannelInterface || !$channel->isEnabled()) {
            return new JsonResponse(['error' => 'Unknown or disabled channel.'], 400);
        }

        $categoryCodes = $request->request->all('categories');

        if (empty($categoryCodes)) {
            return new JsonResponse([
                'error' => 'No categories selected.',
            ], 400);
        }

        // Batch cursor: the sync goes on after the last product of the previous batch
        $afterId = max(0, (int) $request->request->get('after_id'));

        // The enabled products of the selected categories sold on the channel, each once, in ID order
        $idsQb = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT p.id')
            ->from('Sylius\\Component\\Core\\Model\\Product', 'p')
            ->join('p.productTaxons', 'pt')
            ->join('pt.taxon', 't')
            ->andWhere('t.code IN (:codes)')
            ->andWhere('p.enabled = true')
            ->andWhere(':channel MEMBER OF p.channels')
            ->orderBy('p.id', 'ASC')
            ->setParameter('codes', $categoryCodes)
            ->setParameter('channel', $channel);
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

        $websiteUrl = SyncService::channelUrl($channel, $request->getScheme(), $request->getHttpHost());

        try {
            $productsCount = $this->syncService->syncProducts($channel, $websiteUrl, $query);
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

    /**
     * @return array<int, ChannelInterface>
     */
    private function enabledChannels(): array
    {
        return array_values(array_filter(
            $this->channelRepository->findBy(['enabled' => true], ['name' => 'ASC']),
            static fn (mixed $channel): bool => $channel instanceof ChannelInterface,
        ));
    }

    /**
     * The channel chosen on the page, else the one served on the admin's host, else the first one.
     *
     * @param array<int, ChannelInterface> $channels
     */
    private function selectedChannel(array $channels, Request $request): ?ChannelInterface
    {
        $code = $request->query->get('channel');
        foreach ($channels as $channel) {
            if (null !== $code && $channel->getCode() === $code) {
                return $channel;
            }
        }

        $host = strtolower($request->getHost());
        foreach ($channels as $channel) {
            if (null !== $channel->getHostname() && strtolower($channel->getHostname()) === $host) {
                return $channel;
            }
        }

        return $channels[0] ?? null;
    }
}
