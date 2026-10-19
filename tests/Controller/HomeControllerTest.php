<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\OperationType;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CurrencyEnum;
use App\Enum\OperationEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HomeControllerTest extends WebTestCase
{
    public function testAnonymousHomeRedirectsToLogin(): void
    {
        static::createClient()->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testAuthenticatedHomeShowsUser(): void
    {
        $client = static::createClient();
        $username = sprintf('home-%s', uniqid());
        $client->loginUser($this->createUser($username));

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $username);
    }

    /**
     * The "Recent activity" card previously rendered a hardcoded empty state
     * even when transactions existed. It must now list the owner's own
   * movements, and must not leak another user's.
     */
    public function testHomeListsRecentTransactions(): void
    {
        $client = static::createClient();
        $username = sprintf('home-act-%s', uniqid());
        $user = $this->createUser($username);
        $client->loginUser($user);

        $description = sprintf('RECENT%s', strtoupper(substr(uniqid(), -6)));
        $this->attachTransactionTo($user, $description);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#activity-heading', 'Recent activity');
        // Scoped to the activity card: .type-list is shared with the accounts
        // card, and assertSelectorTextContains reads only the first match.
        self::assertSelectorTextContains(
            'section[aria-labelledby="activity-heading"] .type-list',
            $description
        );
    }

    /**
     * A user with no transactions still gets the empty state rather than a
   * broken card.
     */
    public function testHomeShowsEmptyActivityWhenNoTransactions(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(sprintf('home-empty-%s', uniqid())));

        $client->request('GET', '/');

        // Scoped to the activity card: the accounts card carries its own
        // .empty-state, and assertSelectorTextContains reads only the first
        // match, so an unscoped selector would assert the wrong card.
        self::assertSelectorTextContains(
            'section[aria-labelledby="activity-heading"] .empty-state',
            'No recent activity.'
        );
    }

    /**
     * Builds one transaction owned by $owner. Every entity is re-fetched after
     * each flush: $em->clear() detaches anything fetched before it, and a
     * detached association looks like an unmanaged new entity to persist().
     */
    private function attachTransactionTo(User $owner, string $description): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = $em->getRepository(User::class)->find($owner->getId());
        \assert($user instanceof User);

        $account = new Account();
        $account->setUser($user);
        $account->setName(sprintf('HACC%s', strtoupper(substr(uniqid(), -6))));
        $account->setCurrency(CurrencyEnum::BRL);
        $em->persist($account);
        $em->flush();
        $em->clear();

        $user = $em->getRepository(User::class)->find($owner->getId());
        $account = $em->getRepository(Account::class)->find($account->getId());
        $operationType = $em->getRepository(OperationType::class)->findOneBy([], ['id' => 'ASC']);
        if (!$operationType instanceof OperationType) {
            $operationType = new OperationType();
            $operationType->setType(sprintf('HOMEX%s', strtoupper(substr(uniqid(), -6))));
            $em->persist($operationType);
            $em->flush();
            $em->clear();
            $operationType = $em->getRepository(OperationType::class)->find($operationType->getId());
        }

        \assert($user instanceof User);
        \assert($account instanceof Account);
        \assert($operationType instanceof OperationType);

        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setAccount($account);
        $transaction->setOperationType($operationType);
        $transaction->setOperation(OperationEnum::CREDIT);
        $transaction->setMovement(new \DateTime('2026-10-15'));
        $transaction->setLiquidation(new \DateTime('2026-10-17'));
        $transaction->setDescription($description);
        $transaction->setTotalPrice(100.00);

        $em->persist($transaction);
        $em->flush();
        $em->clear();
    }

    private function createUser(string $username): User
    {
        $container = static::getContainer();
        $user = new User();
        $user->setEmail(sprintf('%s@example.com', $username));
        $user->setUsername($username);
        $user->setPassword(
            $container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password-123')
        );

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $entityManager->clear();

        return $user;
    }
}
