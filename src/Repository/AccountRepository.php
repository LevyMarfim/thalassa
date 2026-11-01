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
