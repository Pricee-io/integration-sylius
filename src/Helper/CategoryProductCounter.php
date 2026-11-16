<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Helper;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;

class CategoryProductCounter
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * The products a sync of this category takes on the channel: enabled and sold on it.
     */
    public function countByTaxonCode(string $taxonCode, ChannelInterface $channel): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT p.id)')
            ->from('Sylius\Component\Core\Model\Product', 'p')
            ->join('p.productTaxons', 'pt')
            ->join('pt.taxon', 't')
            ->where('t.code = :code')
            ->andWhere('p.enabled = true')
            ->andWhere(':channel MEMBER OF p.channels')
            ->setParameter('code', $taxonCode)
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
