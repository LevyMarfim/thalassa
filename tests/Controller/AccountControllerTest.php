<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\OperationType;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CurrencyEnum;
use App\Enum\OperationEnum;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountControllerTest extends WebTestCase
{
    private ?User $currentUser = null;

    public function testAnonymousAccountRoutesRedirectToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/accounts');
        self::assertResponseRedirects('/login');

        $client->request('GET', '/accounts/1/edit');
        self::assertResponseRedirects('/login');

        $client->request('POST', '/accounts/1/delete');
        self::assertResponseRedirects('/login');
    }

    public function testIndexShowsOwnAccounts(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        // Capture the name: uniqueName() generates a fresh string per call, so
        // re-calling it in the assertion would compare against a different one.
        $account = $this->createAccount($this->uniqueName('Nubank'));

        $crawler = $client->request('GET', '/accounts');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[name="account_form"]'));
        self::assertSelectorTextContains('.account-list', $account->getName());
    }

    public function testIndexDoesNotShowOtherUsersAccounts(): void
    {
        $client = static::createClient();
        $mine = $this->createUser();
        $client->loginUser($mine);
        $own = $this->createAccount($this->uniqueName('Nubank'));

        // Belong to someone else entirely.
        $other = $this->createUser();
        $this->createAccount($this->uniqueName('Segredo'), $other);

        $client->request('GET', '/accounts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-list', $own->getName());
        self::assertSelectorTextNotContains('.account-list', 'Segredo');
        self::assertCount(1, $this->accountRepository()->findByUser($mine));
    }

    public function testCreateAccountSuccess(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);
        $name = $this->uniqueName('XP');

        $crawler = $client->request('GET', '/accounts');
        $form = $crawler->filter('form[name="account_form"]')->form([
            'account_form[name]' => $name,
            'account_form[currency]' => 'USD',
            'account_form[active]' => '1',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/accounts');
        $client->followRedirect();
        self::assertSelectorTextContains('.account-list', $name);

        $created = $this->findAccount($name);
        self::assertNotNull($created);
        self::assertSame($user->getId(), $created->getUser()?->getId());
        self::assertSame(CurrencyEnum::USD, $created->getCurrency());
        self::assertTrue($created->isActive());
    }

    public function testCreateAccountRejectsBlankName(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $before = $this->countAccounts();

        $crawler = $client->request('GET', '/accounts');
        $form = $crawler->filter('form[name="account_form"]')->form([
            'account_form[name]' => '',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countAccounts());
    }

    public function testCreateAccountRejectsDuplicateName(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $name = $this->uniqueName('Nubank');
        $this->createAccount($name);
        $before = $this->countAccounts();

        $crawler = $client->request('GET', '/accounts');
        $form = $crawler->filter('form[name="account_form"]')->form([
            'account_form[name]' => strtoupper($name),
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countAccounts());
    }

    public function testTwoUsersCanShareAnAccountName(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        // Names are unique per user, not globally.
        $taken = $this->uniqueName('Nubank');
        $this->createAccount($taken);

        $other = $this->createUser();
        $em = $this->entityManager();
        $em->clear();

        $account = new Account();
        $account->setUser($em->getRepository(User::class)->find($other->getId()));
        $account->setName($taken);
        $account->setCurrency(CurrencyEnum::BRL);
        $em->persist($account);
        $em->flush();

        self::assertNotNull($this->findAccount($taken));
    }

    public function testAccountNameIsNormalizedOnSave(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $name = $this->uniqueName('Renda');

        $crawler = $client->request('GET', '/accounts');
        $form = $crawler->filter('form[name="account_form"]')->form([
            'account_form[name]' => sprintf('  %s   Fixa  ', $name),
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/accounts');
        self::assertNotNull($this->findAccount(sprintf('%s Fixa', $name)));
    }

    public function testEditPageRendersPrefilled(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $account = $this->createAccount($this->uniqueName('Nubank'));

        $crawler = $client->request('GET', sprintf('/accounts/%d/edit', $account->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame($account->getName(), $crawler->filter('input[name="account_form[name]"]')->attr('value'));
    }

    public function testEditAccountSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $account = $this->createAccount($this->uniqueName('Nubank'));
        $newName = $this->uniqueName('NubankEdit');

        $crawler = $client->request('GET', sprintf('/accounts/%d/edit', $account->getId()));
        $form = $crawler->filter('form[name="account_form"]')->form([
            'account_form[name]' => $newName,
            'account_form[currency]' => 'USD',
            'account_form[active]' => '0',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/accounts');

        $updated = $this->findAccount($newName);
        self::assertNotNull($updated);
        self::assertSame(CurrencyEnum::USD, $updated->getCurrency());
        self::assertFalse($updated->isActive());
        self::assertNull($this->findAccount($account->getName()));
    }

    public function testEditForeignAccountReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->createAccount($this->uniqueName('Nubank'));

        $foreignOwner = $this->createUser();
        $foreign = $this->createAccount($this->uniqueName('Alheio'), $foreignOwner);

        $client->request('GET', sprintf('/accounts/%d/edit', $foreign->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testEditUnknownAccountReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/accounts/999999/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteAccountSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $account = $this->createAccount($this->uniqueName('Nubank'));

        // Submit the delete form exactly as rendered (it carries the CSRF token).
        $crawler = $client->request('GET', '/accounts');
        $form = $crawler->filter(sprintf('form[action="/accounts/%d/delete"]', $account->getId()))->form();
        $client->submit($form);

        self::assertResponseRedirects('/accounts');
        self::assertNull($this->findAccount($account->getName()));
    }

    /**
     * Regression: a DEBIT row stored as a negative totalPrice (-500) was
     * negated again by the balance query, flipping it to +500 and inflating
     * the total. The operation enum must be the only source of direction.
     */
    public function testBalanceSubtractsNegativeStoredDebit(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $account = $this->createAccount($this->uniqueName('Nubank'));

        $this->attachTransactionTo($account, OperationEnum::CREDIT, 100.00);
        $this->attachTransactionTo($account, OperationEnum::DEBIT, -500.00);

        // 100 in, 500 out => -400. The old query returned +600.
        $crawler = $client->request('GET', '/accounts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-balance', '-400,00');

        // Same repository call, so the dashboard must agree.
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-balance', '-400,00');
    }

    /**
     * The opposite encoding: a DEBIT stored as a positive magnitude. Both
     * spellings must land on the same balance.
     */
    public function testBalanceSubtractsPositiveStoredDebit(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $account = $this->createAccount($this->uniqueName('Nubank'));

        $this->attachTransactionTo($account, OperationEnum::CREDIT, 100.00);
        $this->attachTransactionTo($account, OperationEnum::DEBIT, 500.00);

        $client->request('GET', '/accounts');

        self::assertSelectorTextContains('.account-balance', '-400,00');
    }

    public function testDeleteAccountWithTransactionsIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $account = $this->createAccount($this->uniqueName('Nubank'));
        $this->attachTransactionTo($account);

        $crawler = $client->request('GET', '/accounts');
        $form = $crawler->filter(sprintf('form[action="/accounts/%d/delete"]', $account->getId()))->form();
        $client->submit($form);

        $client->followRedirect();
        self::assertSelectorTextContains('.alert--error', 'still has transactions');
        // The account and its transaction both survive.
        self::assertNotNull($this->findAccount($account->getName()));
    }

    public function testDeleteForeignAccountReturnsNotFoundAndKeepsRow(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->createAccount($this->uniqueName('Nubank'));

        $foreignOwner = $this->createUser();
        $foreign = $this->createAccount($this->uniqueName('Alheio'), $foreignOwner);

        $client->request('POST', sprintf('/accounts/%d/delete', $foreign->getId()), [
            '_token' => 'irrelevant',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->findAccount($foreign->getName()));
    }

    public function testDeleteRejectsGet(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $account = $this->createAccount($this->uniqueName('Nubank'));

        $client->request('GET', sprintf('/accounts/%d/delete', $account->getId()));

        self::assertResponseStatusCodeSame(405);
        self::assertNotNull($this->findAccount($account->getName()));
    }

    public function testDeleteRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $account = $this->createAccount($this->uniqueName('Nubank'));

        $client->request('POST', sprintf('/accounts/%d/delete', $account->getId()), [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->findAccount($account->getName()));
    }

    // -------------------- Helpers --------------------

    /**
     * The test database is shared across methods and names are unique per
     * user, so every test needs its own.
     */
    private function uniqueName(string $prefix): string
    {
        return sprintf('%s %s', $prefix, strtoupper(substr(uniqid(), -5)));
    }

    private function createAccount(string $name, ?User $owner = null): Account
    {
        $em = $this->entityManager();
        $user = $em->getRepository(User::class)->find(($owner ?? $this->currentUser)?->getId());
        \assert($user instanceof User);

        $account = new Account();
        $account->setUser($user);
        $account->setName($name);
        $account->setCurrency(CurrencyEnum::BRL);

        $em->persist($account);
        $em->flush();
        $em->clear();

        return $account;
    }

    /**
     * Attaches a transaction so the account is no longer empty; deletion must
     * then be refused rather than orphaning the row.
     */
    private function attachTransactionTo(
        Account $account,
        OperationEnum $operation = OperationEnum::CREDIT,
        float $totalPrice = 100.00,
    ): void {
        $em = $this->entityManager();
        $em->clear();

        // Ensure the operation type first: it may clear the EM, which would
        // detach anything fetched before it.
        $operationType = $this->ensureOperationType();

        $user = $em->getRepository(User::class)->find($account->getUser()?->getId());
        $managed = $em->getRepository(Account::class)->find($account->getId());
        \assert($user instanceof User);
        \assert($managed instanceof Account);

        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setAccount($managed);
        $transaction->setMovement(new \DateTime('2026-10-15'));
        $transaction->setLiquidation(new \DateTime('2026-10-17'));
        $transaction->setOperation($operation);
        $transaction->setOperationType($operationType);
        $transaction->setDescription(sprintf('TX%s', strtoupper(substr(uniqid(), -6))));
        $transaction->setTotalPrice($totalPrice);

        $em->persist($transaction);
        $em->flush();
        $em->clear();
    }

    /**
     * A transaction needs an operation type, but none may exist yet — this test
     * must not depend on the settings tests having run first, so it creates one.
     */
    private function ensureOperationType(): OperationType
    {
        $em = $this->entityManager();
        $em->clear();

        $type = $em->getRepository(OperationType::class)->findOneBy([], ['id' => 'DESC']);
        if (!$type instanceof OperationType) {
            $type = new OperationType();
            $type->setType(sprintf('OPT%s', strtoupper(substr(uniqid(), -5))));
            $em->persist($type);
            $em->flush();
            $em->clear();

            // Re-fetch: the clear above detached it, and handing a detached
            // entity to a new Transaction makes persist() treat it as unmanaged.
            $type = $em->getRepository(OperationType::class)->findOneBy([], ['id' => 'DESC']);
        }
        \assert($type instanceof OperationType);

        return $type;
    }

    private function findAccount(string $name): ?Account
    {
        // Clear first so a rejected submit cannot be masked by the identity map.
        $em = $this->entityManager();
        $em->clear();

        $account = $em->getRepository(Account::class)->findOneBy(['name' => $name]);

        return $account instanceof Account ? $account : null;
    }

    private function countAccounts(): int
    {
        return $this->entityManager()->getRepository(Account::class)->count([]);
    }

    private function accountRepository(): AccountRepository
    {
        return $this->entityManager()->getRepository(Account::class);
    }

    private function createUser(): User
    {
        $container = static::getContainer();
        $username = sprintf('acct-%s', uniqid());

        $user = new User();
        $user->setEmail(sprintf('%s@example.com', $username));
        $user->setUsername($username);
        $user->setPassword(
            $container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password-123')
        );

        $em = $this->entityManager();
        $em->persist($user);
        $em->flush();
        $em->clear();

        $this->currentUser = $user;

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
