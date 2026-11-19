<?php

namespace App\Repository;

use App\Dto\TransactionFilter;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CurrencyEnum;
use App\Enum\OperationEnum;
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

        // Movement date remains the tie-breaker for every sort so a page of
        // equally priced rows still has a stable, predictable order.
        $sort = null === $filter ? TransactionFilter::SORT_MOVEMENT_DESC : $filter->getSort();
        $direction = \in_array($sort, [
            TransactionFilter::SORT_MOVEMENT_ASC,
            TransactionFilter::SORT_TOTAL_ASC,
        ], true) ? 'ASC' : 'DESC';

        if (TransactionFilter::SORT_TOTAL_DESC === $sort || TransactionFilter::SORT_TOTAL_ASC === $sort) {
            $qb->orderBy('t.totalPrice', $direction)
                ->addOrderBy('t.movement', 'DESC');
        } else {
            $qb->orderBy('t.movement', $direction);
        }

        return $qb
            ->addOrderBy('t.id', $direction)
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

    /**
     * Whole-ledger net movement per currency, independent of the current list
     * filter, so the summary strip keeps describing the user's finances even
     * while the table is narrowed to one account or date range.
     *
     * The CASE mirrors findBalancesByUser(): the operation decides direction and
     * ABS() tolerates legacy rows whose debit sign was stored inconsistently.
     *
     * @return array<string, array{currency: string, total: float, count: int}>
     */
    public function findTotalsByUser(User $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('a.currency AS currency')
            ->addSelect('COUNT(t.id) AS transaction_count')
            ->addSelect(
                'COALESCE(SUM(CASE WHEN t.operation = :credit '
                .'THEN ABS(t.totalPrice) ELSE -ABS(t.totalPrice) END), 0) AS total'
            )
            ->innerJoin('t.account', 'a')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->setParameter('credit', OperationEnum::CREDIT->value)
            ->groupBy('a.currency')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $raw = $row['currency'];
            $currency = $raw instanceof CurrencyEnum ? $raw->value : (string) $raw;
            $totals[$currency] = [
                'currency' => $currency,
                'total' => round((float) $row['total'], 2),
                'count' => (int) $row['transaction_count'],
            ];
        }

        return $totals;
    }
}
