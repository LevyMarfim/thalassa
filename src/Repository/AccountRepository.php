<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\User;
use App\Enum\OperationEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Every read in the app must go through here (or an equivalent user filter):
     * Account is only scoped by this column, so an unscoped query would leak
     * one user's accounts to another.
     *
     * @return Account[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Used by UniqueEntity (repositoryMethod) so a duplicate name is rejected as
     * a clean 422. Names are unique per user, not globally: two people can both
     * have a "Nubank".
     */
    public function findOneByNameForUser(array $criteria): ?Account
    {
        $user = $criteria['user'] ?? null;
        $name = $criteria['name'] ?? null;
        if (!$user instanceof User || !\is_string($name) || '' === trim($name)) {
            return null;
        }

        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->andWhere('LOWER(TRIM(a.name)) = LOWER(:name)')
            ->setParameter('user', $user)
            ->setParameter('name', trim($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Balance is never stored: it is the signed sum of this account's
     * transactions, so it cannot drift away from the ledger. CREDIT adds,
     * DEBIT subtracts — a single CASE expression, aggregated in SQL rather than
     * hydrating every transaction into PHP.
     *
     * Writes store the sign already (Transaction::setTotalPrice() makes a debit
     * negative), so in principle SUM(t.totalPrice) would do. ABS() is kept as
     * tolerance for rows written before that normalisation or imported from
     * elsewhere: taking the magnitude and letting the operation enum supply the
     * direction makes both encodings agree. Without it, a DEBIT stored as -500
     * was negated into +500, silently reversing the sign and inflating the
     * total. Version20261031120000 backfills the legacy rows.
     *
     * Uses the enum's string backing values rather than the PHP enum cases,
     * because DQL cannot bind an enum case into a comparison.
     *
     * @return array<int, float> accountId => signed balance
     */
    public function findBalancesByUser(User $user): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.id AS account_id')
            ->addSelect(
                'COALESCE(SUM(CASE WHEN t.operation = :credit THEN ABS(t.totalPrice) ELSE -ABS(t.totalPrice) END), 0)'
            )
            ->leftJoin('a.transactions', 't')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->setParameter('credit', OperationEnum::CREDIT->value)
            ->groupBy('a.id')
            ->getQuery()
            ->getArrayResult();

        $balances = [];
        foreach ($rows as $row) {
            $balances[(int) $row['account_id']] = (float) $row[1];
        }

        return $balances;
    }

    /**
     * Month-end balances for every account that has activity on or before the
     * end of the current month.
     *
     * Unlike findBalancesByUser(), which answers "what is the balance now",
     * this one answers "what was the balance at the end of each month". The
     * opening balance is the signed sum of everything before the first month
     * of the window, so the first plotted point already carries all older
     * activity instead of starting the line at zero.
     *
     * The grouping is done in SQL with PostgreSQL's TO_CHAR because DQL has no
     * date_trunc()/month-bucket function. Both queries join the account table
     * and filter on its owner, never on the request input, so one user's
     * ledger cannot leak into another's chart.
     *
     * @param int $months Number of calendar months to return, ending with the current one
     *
     * @return array{
     *     months: list<string>,
     *     balances: array<int, list<float>>,
     * } months are Y-m keys in chronological order; balances maps account id
     *   to one float per month (balance at that month's end)
     */
    public function findMonthlyBalancesByUser(User $user, int $months = 12): array
    {
        $months = max(1, $months);
        $timezone = new \DateTimeZone('America/Sao_Paulo');
        $currentMonth = (new \DateTimeImmutable('now', $timezone))
            ->modify('first day of this month')
            ->setTime(0, 0);
        $firstMonth = $currentMonth->modify(\sprintf('-%d months', $months - 1));
        $lastDay = $currentMonth->modify('last day of this month');

        $monthKeys = [];
        $cursor = $firstMonth;
        for ($i = 0; $i < $months; ++$i) {
            $monthKeys[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('first day of next month');
        }

        $connection = $this->getEntityManager()->getConnection();
        // A debit is stored negative, but ABS() keeps this correct for legacy
        // rows the same way findBalancesByUser() does.
        $signedTotal = 'SUM(CASE WHEN t.operation = :credit THEN ABS(t.total_price) ELSE -ABS(t.total_price) END)';

        $openingRows = $connection->executeQuery(
            <<<SQL
                SELECT t.account_id AS account_id, $signedTotal AS balance
                FROM app_transaction t
                INNER JOIN app_account a ON a.id = t.account_id
                WHERE a.user_id = :user AND t.movement < :start
                GROUP BY t.account_id
                SQL,
            [
                'credit' => OperationEnum::CREDIT->value,
                'user' => $user->getId(),
                'start' => $firstMonth->format('Y-m-d'),
            ],
        )->fetchAllAssociative();

        $monthlyRows = $connection->executeQuery(
            <<<SQL
                SELECT t.account_id AS account_id,
                       TO_CHAR(t.movement, 'YYYY-MM') AS month_key,
                       $signedTotal AS net
                FROM app_transaction t
                INNER JOIN app_account a ON a.id = t.account_id
                WHERE a.user_id = :user AND t.movement >= :start AND t.movement <= :end
                GROUP BY t.account_id, TO_CHAR(t.movement, 'YYYY-MM')
                SQL,
            [
                'credit' => OperationEnum::CREDIT->value,
                'user' => $user->getId(),
                'start' => $firstMonth->format('Y-m-d'),
                'end' => $lastDay->format('Y-m-d'),
            ],
        )->fetchAllAssociative();

        $opening = [];
        foreach ($openingRows as $row) {
            $opening[(int) $row['account_id']] = (float) $row['balance'];
        }

        $nets = [];
        foreach ($monthlyRows as $row) {
            $nets[(int) $row['account_id']][$row['month_key']] = (float) $row['net'];
        }

        $balances = [];
        $accountIds = array_unique([...array_keys($opening), ...array_keys($nets)]);
        foreach ($accountIds as $accountId) {
            $running = $opening[$accountId] ?? 0.0;
            $values = [];
            foreach ($monthKeys as $monthKey) {
                $running += $nets[$accountId][$monthKey] ?? 0.0;
                $values[] = round($running, 2);
            }
            $balances[$accountId] = $values;
        }

        return ['months' => $monthKeys, 'balances' => $balances];
    }

    public function countTransactions(Account $account): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(t.id)')
            ->innerJoin('a.transactions', 't')
            ->andWhere('a = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
