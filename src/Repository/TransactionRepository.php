<?php

namespace App\Repository;

use App\Dto\TransactionFilter;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CurrencyEnum;
use App\Enum\OperationEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * The one place that turns a filter into DQL criteria. The listing and both
     * chart aggregates call it, so a narrowed table can no longer sit above a
     * full-ledger chart. User scope is always applied first: a filter can only
     * shrink the result set, never widen it past the owner's rows.
     */
    private function applyCriteria(QueryBuilder $qb, User $user, ?TransactionFilter $filter): void
    {
        $qb->andWhere('t.user = :user')
            ->setParameter('user', $user);

        if (null === $filter) {
            return;
        }

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

        // movement is a DATE column, so the from/to pair compares calendar days
        // and both bounds are inclusive.
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
        $qb = $this->createQueryBuilder('t');
        $this->applyCriteria($qb, $user, $filter);

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

    public function countByUser(User $user, ?TransactionFilter $filter = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)');
        $this->applyCriteria($qb, $user, $filter);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Credits and debits per calendar month and currency for the whole filtered
     * history — not just the last 12 months. The Stimulus controller slices this
     * to the selected range, the same way the dashboard balance chart does, so
     * an account whose only movements are old still has something to show.
     *
     * A movement-date filter still caps the history: its bounds are part of the
     * shared criteria and the end month is clamped to "to", so a narrow filter
     * cannot advertise ranges it has no data for. Every other criterion is also
     * applied through that helper, exactly as it is to the listing.
     *
     * Only the four columns the charts need are selected (no entity hydration),
     * and the grouping happens in PHP so the DQL stays portable — DQL has no
     * TO_CHAR()/date_trunc() equivalent.
     *
     * @return array{
     *     months: list<string>,
     *     rows: list<array{month: string, currency: string, credits: float, debits: float}>,
     * }
     */
    public function findMonthlyActivityByUser(User $user, ?TransactionFilter $filter = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.movement AS movement')
            ->addSelect('t.operation AS operation')
            ->addSelect('t.totalPrice AS totalPrice')
            ->addSelect('a.currency AS currency')
            ->innerJoin('t.account', 'a');
        $this->applyCriteria($qb, $user, $filter);

        $rows = $qb->getQuery()->getArrayResult();
        if ([] === $rows) {
            return ['months' => [], 'rows' => []];
        }

        $activity = [];
        $activeMonths = [];
        foreach ($rows as $row) {
            $movement = $row['movement'];
            $month = $movement instanceof \DateTimeInterface
                ? $movement->format('Y-m')
                : substr((string) $movement, 0, 7);
            $activeMonths[$month] = true;

            $currency = self::scalarValue($row['currency']);
            $key = $currency.'|'.$month;
            $amount = abs((float) $row['totalPrice']);

            $activity[$key] ??= ['month' => $month, 'currency' => $currency, 'credits' => 0.0, 'debits' => 0.0];
            if (self::scalarValue($row['operation']) === OperationEnum::CREDIT->value) {
                $activity[$key]['credits'] += $amount;
            } else {
                $activity[$key]['debits'] += $amount;
            }
        }

        $months = $this->monthKeysForActivity($activeMonths, $filter);
        if ([] === $months) {
            return ['months' => [], 'rows' => []];
        }

        return ['months' => $months, 'rows' => array_values($activity)];
    }

    /**
     * Absolute money moved per operation and currency. Debits are stored
     * negative, so ABS() keeps the magnitude instead of letting credits and
     * debits cancel each other out inside a doughnut slice.
     *
     * @return list<array{operation: string, currency: string, total: float}>
     */
    public function findOperationTotalsByUser(User $user, ?TransactionFilter $filter = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.operation AS operation')
            ->addSelect('a.currency AS currency')
            ->addSelect('SUM(ABS(t.totalPrice)) AS total')
            ->innerJoin('t.account', 'a')
            ->groupBy('t.operation', 'a.currency');
        $this->applyCriteria($qb, $user, $filter);

        return $this->mapValueRows($qb->getQuery()->getArrayResult(), 'operation');
    }

    /**
     * Absolute money moved per operation type and currency. One doughnut per
     * currency keeps BRL and USD values from being added together.
     *
     * @return list<array{type: string, currency: string, total: float}>
     */
    public function findOperationTypeTotalsByUser(User $user, ?TransactionFilter $filter = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('ot.type AS type')
            ->addSelect('a.currency AS currency')
            ->addSelect('SUM(ABS(t.totalPrice)) AS total')
            ->innerJoin('t.operationType', 'ot')
            ->innerJoin('t.account', 'a')
            ->groupBy('ot.type', 'a.currency');
        $this->applyCriteria($qb, $user, $filter);

        return $this->mapValueRows($qb->getQuery()->getArrayResult(), 'type');
    }

    /**
     * Absolute monthly totals per operation type and currency, across the full
     * filtered history so the type picker can show any period already in the
     * ledger. Movement-date filters still cap the month axis.
     *
     * @return array{
     *     months: list<string>,
     *     rows: list<array{month: string, type: string, currency: string, total: float}>,
     * }
     */
    public function findMonthlyTypeActivityByUser(User $user, ?TransactionFilter $filter = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.movement AS movement')
            ->addSelect('t.totalPrice AS totalPrice')
            ->addSelect('ot.type AS type')
            ->addSelect('a.currency AS currency')
            ->innerJoin('t.operationType', 'ot')
            ->innerJoin('t.account', 'a');
        $this->applyCriteria($qb, $user, $filter);

        $activity = [];
        $activeMonths = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $movement = $row['movement'];
            $month = $movement instanceof \DateTimeInterface
                ? $movement->format('Y-m')
                : substr((string) $movement, 0, 7);
            $activeMonths[$month] = true;

            $key = self::scalarValue($row['currency']).'|'.self::scalarValue($row['type']).'|'.$month;
            $activity[$key] ??= [
                'month' => $month,
                'type' => self::scalarValue($row['type']),
                'currency' => self::scalarValue($row['currency']),
                'total' => 0.0,
            ];
            $activity[$key]['total'] += abs((float) $row['totalPrice']);
        }

        $months = [] === $activeMonths ? [] : $this->monthKeysForActivity($activeMonths, $filter);

        return [
            'months' => $months,
            'rows' => array_values($activity),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function mapValueRows(array $rows, string $groupKey): array
    {
        return array_map(
            static fn (array $row): array => [
                $groupKey => self::scalarValue($row[$groupKey]),
                'currency' => self::scalarValue($row['currency']),
                'total' => round((float) $row['total'], 2),
            ],
            $rows,
        );
    }

    /**
     * The month axis always reaches the current month (or the filter's "to"
     * bound), so even a dormant ledger can be inspected over a long range.
     *
     * @param array<string, true> $activeMonths
     *
     * @return list<string> Y-m keys, chronological
     */
    private function monthKeysForActivity(array $activeMonths, ?TransactionFilter $filter): array
    {
        $timezone = new \DateTimeZone('America/Sao_Paulo');
        $current = (new \DateTimeImmutable('now', $timezone))->modify('first day of this month');
        $end = max($current->format('Y-m'), max(array_keys($activeMonths)));
        $to = $filter?->getMovementTo();
        if (null !== $to) {
            $end = min($end, $to->modify('first day of this month')->format('Y-m'));
        }

        $start = min(array_keys($activeMonths));
        if ($end < $start) {
            return [];
        }

        return $this->monthKeys(
            new \DateTimeImmutable($start.'-01', $timezone),
            new \DateTimeImmutable($end.'-01', $timezone),
        );
    }

    /**
     * @return list<string> Y-m keys, chronological
     */
    private function monthKeys(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if ($end < $start) {
            return [];
        }

        $months = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('first day of next month');
        }

        return $months;
    }

    /** Doctrine may hydrate an enum column as its case or its backing value. */
    private static function scalarValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    /**
     * Net movement per currency for the current list filter. The summary strip
     * now describes the same slice as the table and charts, using the shared
     * criteria helper so a narrowed view cannot show whole-ledger totals.
     *
     * The CASE mirrors findBalancesByUser(): the operation decides direction and
     * ABS() tolerates legacy rows whose debit sign was stored inconsistently.
     *
     * @return array<string, array{currency: string, total: float, count: int}>
     */
    public function findTotalsByUser(User $user, ?TransactionFilter $filter = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('a.currency AS currency')
            ->addSelect('COUNT(t.id) AS transaction_count')
            ->addSelect(
                'COALESCE(SUM(CASE WHEN t.operation = :credit '
                .'THEN ABS(t.totalPrice) ELSE -ABS(t.totalPrice) END), 0) AS total'
            )
            ->innerJoin('t.account', 'a')
            ->setParameter('credit', OperationEnum::CREDIT->value)
            ->groupBy('a.currency');
        $this->applyCriteria($qb, $user, $filter);

        $rows = $qb->getQuery()->getArrayResult();

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
