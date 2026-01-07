<?php

declare(strict_types=1);

namespace PriceeIO\SyncPlugin\Helper;

use Doctrine\ORM\EntityManagerInterface;

class CategoryProductCounter
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function countByTaxonCode(string $taxonCode): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from('Sylius\Component\Core\Model\Product', 'p')
            ->join('p.productTaxons', 'pt')
            ->join('pt.taxon', 't')
            ->where('t.code = :code')
            ->setParameter('code', $taxonCode)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
