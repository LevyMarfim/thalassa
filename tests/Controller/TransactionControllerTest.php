<?php

namespace App\Tests\Controller;

use App\Entity\OperationType;
use App\Entity\Ticker;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\OperationEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TransactionControllerTest extends WebTestCase
{
    private ?User $currentUser = null;

    public function testAnonymousTransactionsRedirectToLogin(): void
    {
        static::createClient()->request('GET', '/transactions');

        self::assertResponseRedirects('/login');
    }

    public function testAnonymousEditRedirectsToLogin(): void
    {
        static::createClient()->request('GET', '/transactions/1/edit');

        self::assertResponseRedirects('/login');
    }

    public function testAnonymousDeleteRedirectsToLogin(): void
    {
        static::createClient()->request('POST', '/transactions/1/delete');

        self::assertResponseRedirects('/login');
    }

    public function testIndexShowsOwnTransaction(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();
        $transaction = $this->createTransaction($this->uniqueDescription('COMPRA'), 1234.56);

        $crawler = $client->request('GET', '/transactions');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[name="transaction_form"]'));
        self::assertSelectorTextContains('.tx-table', $transaction->getDescription());
        self::assertSelectorTextContains('.tx-table', '1.234,56');
    }

    public function testIndexDoesNotShowOtherUsersTransactions(): void
    {
        $client = static::createClient();
        $mine = $this->createUser();
        $client->loginUser($mine);

        // One row of my own (so the table renders at all) ...
        $this->seedTransactionData();
        $own = $this->createTransaction($this->uniqueDescription('MEU'), 10.00, $mine);

        // ... and one belonging to someone else entirely.
        $other = $this->createUser();
        $this->createTransaction($this->uniqueDescription('SEGREDO'), 999.99, $other);

        $client->request('GET', '/transactions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tx-table', $own->getDescription());
        self::assertSelectorTextNotContains('.tx-table', 'SEGREDO');
        self::assertSame(1, $this->transactionRepository()->countByUser($mine));
    }

    public function testCreateTransactionSuccess(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);
        $data = $this->seedTransactionData();
        $description = $this->uniqueDescription('VENDA');

        $crawler = $client->request('GET', '/transactions');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[movement]' => '2026-10-20',
            'transaction_form[liquidation]' => '2026-10-22',
            'transaction_form[operation]' => 'CREDIT',
            'transaction_form[operationType]' => (string) $data['operationType']->getId(),
            'transaction_form[ticker]' => (string) $data['ticker']->getId(),
            'transaction_form[description]' => $description,
            'transaction_form[totalPrice]' => '2500.75',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/transactions');
        $client->followRedirect();
        self::assertSelectorTextContains('.tx-table', $description);

        $created = $this->findTransaction($description);
        self::assertNotNull($created);
        self::assertSame($user->getId(), $created->getUser()?->getId());
        self::assertSame(OperationEnum::CREDIT, $created->getOperation());
        self::assertEqualsWithDelta(2500.75, $created->getTotalPrice(), 0.001);
    }

    public function testCreateTransactionWithoutTicker(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $description = $this->uniqueDescription('CONTA');

        $crawler = $client->request('GET', '/transactions');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[movement]' => '2026-10-20',
            'transaction_form[liquidation]' => '2026-10-22',
            'transaction_form[operation]' => 'DEBIT',
            'transaction_form[operationType]' => (string) $data['operationType']->getId(),
            'transaction_form[ticker]' => '',
            'transaction_form[description]' => $description,
            'transaction_form[totalPrice]' => '89.90',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/transactions');
        $created = $this->findTransaction($description);
        self::assertNotNull($created);
        self::assertNull($created->getTicker());
    }

    public function testCreateTransactionRejectsBlankRequiredFields(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $before = $this->countTransactions();

        $crawler = $client->request('GET', '/transactions');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[description]' => '',
            'transaction_form[totalPrice]' => '',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countTransactions());
    }

    public function testEditPageRendersPrefilled(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();
        $transaction = $this->createTransaction($this->uniqueDescription('COMPRA'), 1234.56);

        $crawler = $client->request('GET', sprintf('/transactions/%d/edit', $transaction->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame(
            $transaction->getDescription(),
            $crawler->filter('input[name="transaction_form[description]"]')->attr('value')
        );
    }

    public function testEditTransactionSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();
        $transaction = $this->createTransaction($this->uniqueDescription('COMPRA'), 1234.56);
        $newDescription = $this->uniqueDescription('RENOMEADA');

        $crawler = $client->request('GET', sprintf('/transactions/%d/edit', $transaction->getId()));
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[description]' => $newDescription,
            'transaction_form[totalPrice]' => '1300.00',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/transactions');
        $updated = $this->findTransaction($newDescription);
        self::assertNotNull($updated);
        self::assertEqualsWithDelta(1300.00, $updated->getTotalPrice(), 0.001);
        self::assertNull($this->findTransaction($transaction->getDescription()));
    }

    public function testEditForeignTransactionReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $foreignOwner = $this->createUser();
        $foreign = $this->createTransaction($this->uniqueDescription('ALHEIO'), 10.00, $foreignOwner);

        $client->request('GET', sprintf('/transactions/%d/edit', $foreign->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testEditUnknownTransactionReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/transactions/999999/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteTransactionSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();
        $transaction = $this->createTransaction($this->uniqueDescription('COMPRA'), 1234.56);

        $crawler = $client->request('GET', '/transactions');
        $form = $crawler->filter(sprintf(
            'form[action="/transactions/%d/delete"]',
            $transaction->getId()
        ))->form();
        $client->submit($form);

        self::assertResponseRedirects('/transactions');
        self::assertNull($this->findTransaction($transaction->getDescription()));
    }

    public function testDeleteForeignTransactionReturnsNotFoundAndKeepsRow(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $foreignOwner = $this->createUser();
        $foreign = $this->createTransaction($this->uniqueDescription('ALHEIO'), 10.00, $foreignOwner);

        $client->request('POST', sprintf('/transactions/%d/delete', $foreign->getId()), [
            '_token' => 'irrelevant',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->findTransaction($foreign->getDescription()));
    }

    public function testDeleteRejectsGet(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();
        $transaction = $this->createTransaction($this->uniqueDescription('COMPRA'), 1234.56);

        $client->request('GET', sprintf('/transactions/%d/delete', $transaction->getId()));

        self::assertResponseStatusCodeSame(405);
        self::assertNotNull($this->findTransaction($transaction->getDescription()));
    }

    public function testDeleteRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();
        $transaction = $this->createTransaction($this->uniqueDescription('COMPRA'), 1234.56);

        $client->request('POST', sprintf('/transactions/%d/delete', $transaction->getId()), [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->findTransaction($transaction->getDescription()));
    }

    // -------------------- Helpers --------------------

    /**
     * The test database is shared across test methods and descriptions are the
     * only human key on a transaction, so every test needs its own.
     */
    private function uniqueDescription(string $prefix): string
    {
        return sprintf('%s %s', $prefix, uniqid());
    }

    /**
     * Seeds the shared operation type + ticker that transactions point at.
     *
     * @return array{operationType: OperationType, ticker: Ticker}
     */
    private function seedTransactionData(): array
    {
        $em = $this->entityManager();

        $operationType = new OperationType();
        $operationType->setType(sprintf('OPT%s', strtoupper(substr(uniqid(), -5))));

        $ticker = new Ticker();
        $ticker->setTicker(sprintf('T%s', strtoupper(substr(uniqid(), -6))));

        $em->persist($operationType);
        $em->persist($ticker);
        $em->flush();
        $em->clear();

        return ['operationType' => $operationType, 'ticker' => $ticker];
    }

    private function createTransaction(string $description, float $totalPrice, ?User $owner = null): Transaction
    {
        $em = $this->entityManager();

        // Re-fetch the shared fixtures (the EM was cleared, which detaches
        // everything) so every reference below is a managed entity.
        $operationType = $em->getRepository(OperationType::class)->findOneBy([], ['id' => 'DESC']);
        $ticker = $em->getRepository(Ticker::class)->findOneBy([], ['id' => 'DESC']);

        $user = $em->getRepository(User::class)->find(($owner ?? $this->currentUser)?->getId());
        \assert($user instanceof User);

        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setMovement(new \DateTime('2026-10-15'));
        $transaction->setLiquidation(new \DateTime('2026-10-17'));
        $transaction->setOperation(OperationEnum::DEBIT);
        $transaction->setOperationType($operationType);
        $transaction->setTicker($ticker);
        $transaction->setDescription($description);
        $transaction->setTotalPrice($totalPrice);

        $em->persist($transaction);
        $em->flush();
        $em->clear();

        return $transaction;
    }

    private function createUser(): User
    {
        $container = static::getContainer();
        $username = sprintf('tx-%s', uniqid());

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

    private function findTransaction(string $description): ?Transaction
    {
        // Clear first so a rejected submit cannot be masked by the identity map.
        $em = $this->entityManager();
        $em->clear();

        return $em->getRepository(Transaction::class)->findOneBy(['description' => $description]);
    }

    private function countTransactions(): int
    {
        return $this->entityManager()->getRepository(Transaction::class)->count([]);
    }

    private function transactionRepository(): \App\Repository\TransactionRepository
    {
        return $this->entityManager()->getRepository(Transaction::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
