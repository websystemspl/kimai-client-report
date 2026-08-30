<?php

namespace KimaiPlugin\ClientReportBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\ClientReportBundle\Entity\SharedReport;

/**
 * @extends ServiceEntityRepository<SharedReport>
 */
class SharedReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedReport::class);
    }

    public function findByToken(string $token): ?SharedReport
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * @return array<SharedReport>
     */
    public function findAllForList(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(SharedReport $report): void
    {
        $em = $this->getEntityManager();
        $em->persist($report);
        $em->flush();
    }

    public function remove(SharedReport $report): void
    {
        $em = $this->getEntityManager();
        $em->remove($report);
        $em->flush();
    }
}
