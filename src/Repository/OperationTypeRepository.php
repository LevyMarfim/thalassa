<?php

namespace App\Repository;

use App\Entity\OperationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OperationType>
 */
class OperationTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OperationType::class);
    }

    /**
     * app_transaction.operation_type_id is NOT NULL with a NO ACTION FK, so a
     * type in use physically cannot be deleted. Counting first turns what would
     * be a raw 500 from the database into a message the user can act on.
     */
    public function countTransactions(OperationType $operationType): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(t.id)')
            ->innerJoin('o.transactions', 't')
            ->andWhere('o = :operationType')
            ->setParameter('operationType', $operationType)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Used by UniqueEntity (repositoryMethod) so duplicates are rejected as a
     * clean 422 before the DB unique index on LOWER(TRIM(type)) is reached.
     * The shape must mirror that index exactly, or the form and the database
     * would disagree about what counts as a duplicate.
     */
    public function findOneByTypeIgnoreCase(array $criteria): ?OperationType
    {
        $type = $criteria['type'] ?? null;
        if (!\is_string($type) || '' === trim($type)) {
            return null;
        }

        return $this->createQueryBuilder('o')
            ->andWhere('LOWER(TRIM(o.type)) = LOWER(:type)')
            ->setParameter('type', trim($type))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

//    /**
//     * @return OperationType[] Returns an array of OperationType objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('o')
//            ->andWhere('o.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('o.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?OperationType
//    {
//        return $this->createQueryBuilder('o')
//            ->andWhere('o.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
