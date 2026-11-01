<?php

namespace App\Repository;

use App\Dto\TransactionFilter;
use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Every read in the app must go through here (or an equivalent user filter):
     * Transaction is only scoped by this column, so an unscoped query would leak
     * one user's ledger to another.
     *
     * A filter can only ever shrink the result set: the user scope above is
     * applied first and every criterion below is an additional AND.
     *
     * @return Transaction[]
     */
    public function findByUser(User $user, ?TransactionFilter $filter = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user);

        if (null !== $filter) {
            if (null !== $filter->getAccount()) {
                $qb->andWhere('t.account = :account')
                    ->setParameter('account', $filter->getAccount());
            }

            if (null !== $filter->getOperation()) {
                $qb->andWhere('t.operation = :operation')
                    ->setParameter('operation', $filter->getOperation());
            }

            if (null !== $filter->getOperationType()) {
                $qb->andWhere('t.operationType = :operationType')
                    ->setParameter('operationType', $filter->getOperationType());
            }

            if (null !== $filter->getTicker()) {
                $qb->andWhere('t.ticker = :ticker')
                    ->setParameter('ticker', $filter->getTicker());
            }

            // movement/liquidation are DATE columns, so a from/to pair compares
            // calendar days. Both bounds are inclusive.
            if (null !== $filter->getMovementFrom()) {
                $qb->andWhere('t.movement >= :movementFrom')
                    ->setParameter('movementFrom', $filter->getMovementFrom());
            }

            if (null !== $filter->getMovementTo()) {
                $qb->andWhere('t.movement <= :movementTo')
                    ->setParameter('movementTo', $filter->getMovementTo());
            }

            $description = $filter->getDescription();
            if (null !== $description && '' !== trim($description)) {
                // LOWER() on both sides keeps the match case-insensitive on
                // PostgreSQL, where LIKE is case-sensitive.
                $qb->andWhere('LOWER(t.description) LIKE :description')
                    ->setParameter('description', '%'.mb_strtolower(trim($description)).'%');
            }
        }

        return $qb
            ->orderBy('t.movement', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
