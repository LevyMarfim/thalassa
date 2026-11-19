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
use Symfony\Component\DomCrawler\Crawler;
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
     * The Accounts card uses the same shortcut as the Accounts page: clicking
     * an account opens its transactions with that account already filtered.
     */
    public function testHomeAccountNameLinksToFilteredTransactions(): void
    {
        $client = static::createClient();
        $user = $this->createUser(sprintf('home-account-link-%s', uniqid()));
        $client->loginUser($user);

        $date = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))
            ->format('Y-m-10');
        $account = $this->seedChartAccount($user, CurrencyEnum::BRL, [
            [$date, OperationEnum::CREDIT, 100.00],
        ]);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter(sprintf(
            'section[aria-labelledby="accounts-heading"] a.type-list__name[href="/transactions?account=%d"]',
            $account->getId(),
        ));
        self::assertCount(1, $link);
        self::assertStringContainsString($account->getName(), $link->text());

        $client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tx-filter', $account->getName());
        self::assertSelectorTextContains('.tx-table', sprintf('Chart %s', $date));
    }

    /**
     * A debit is money out, so the activity card shows it negative — the same
     * direction the account balance sums with.
     */
    public function testHomeShowsDebitsAsNegativeAmounts(): void
    {
        $client = static::createClient();
        $username = sprintf('home-debit-%s', uniqid());
        $user = $this->createUser($username);
        $client->loginUser($user);

        $description = sprintf('DEBIT%s', strtoupper(substr(uniqid(), -6)));
        $this->attachTransactionTo($user, $description, OperationEnum::DEBIT, 250.00);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter('section[aria-labelledby="activity-heading"] .type-list__row')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->text(), $description)
        );
        self::assertCount(1, $row);
        self::assertStringContainsString('-250,00', $row->text());
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
     * The server hands Chart.js one cumulative series per account from the
     * first transaction onward: an opening credit carries through the later
     * months, a debit lowers the line, and a later credit raises it. Stimulus
     * turns the JSON into an interactive chart on the client, so the data
     * attribute is the contract this test can verify.
     */
    public function testHomePlotsMonthEndBalancesPerAccount(): void
    {
        $client = static::createClient();
        $user = $this->createUser(sprintf('home-chart-%s', uniqid()));
        $client->loginUser($user);

        $firstOfMonth = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))
            ->modify('first day of this month');
        $lastMonth = $firstOfMonth->modify('-1 month');
        $beforeWindow = $firstOfMonth->modify('-13 months');

        $account = $this->seedChartAccount($user, CurrencyEnum::BRL, [
            [$beforeWindow->format('Y-m-10'), OperationEnum::CREDIT, 1000.00],
            [$lastMonth->format('Y-m-15'), OperationEnum::DEBIT, 200.00],
            [$firstOfMonth->format('Y-m-05'), OperationEnum::CREDIT, 50.00],
        ]);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#chart-heading', 'Account value by month');
        self::assertSelectorTextContains('.chart-summary', 'Last 12 months');
        self::assertSame(
            '1Y',
            $crawler->filter('.chart-panel')->first()->filter('.chart-range__button[aria-pressed="true"]')->text(),
        );

        // Fourteen months of history: the year and all-time options are
        // offered, but the two-year window is not.
        $ranges = $crawler->filter('.chart-panel')->first()
            ->filter('.chart-range__button')->each(
                static fn (Crawler $button): string => $button->text()
            );
        self::assertContains('1Y', $ranges);
        self::assertContains('All', $ranges);
        self::assertNotContains('2Y', $ranges);
        self::assertNotContains('3Y', $ranges);
        self::assertNotContains('5Y', $ranges);

        $data = $this->panelData($crawler, 'Real (BRL)');
        self::assertSame('BRL', $data['currency']);
        self::assertSame('R$', $data['symbol']);
        // The server sends the whole history; the default 1Y view is sliced
        // client-side, so the data attribute holds all fourteen months.
        self::assertCount(14, $data['labels']);
        self::assertCount(1, $data['datasets']);

        $dataset = $data['datasets'][0];
        self::assertSame($account->getId(), $dataset['accountId']);
        self::assertSame($account->getName(), $dataset['label']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $dataset['color']);

        // Opening 1.000 carried through the twelve months before the debit,
        // then 800 after the debit, then 850 after the credit.
        self::assertSame(
            [...array_fill(0, 12, 1000.0), 800.0, 850.0],
            array_map(static fn ($value): float => (float) $value, $dataset['data']),
        );
    }

    /**
     * The colour chosen on the account edit form must be the one Chart.js draws:
     * the server passes it straight through in the dataset payload.
     */
    public function testHomeUsesTheAccountsSavedChartColor(): void
    {
        $client = static::createClient();
        $user = $this->createUser(sprintf('home-color-%s', uniqid()));
        $client->loginUser($user);

        $date = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))
            ->format('Y-m-05');
        $this->seedChartAccount(
            $user,
            CurrencyEnum::BRL,
            [[$date, OperationEnum::CREDIT, 100.00]],
            '#ec4899',
        );

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $data = $this->panelData($crawler, 'Real (BRL)');
        self::assertSame('#ec4899', $data['datasets'][0]['color']);
    }

    public function testHomeShowsEmptyChartWhenNoTransactions(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser(sprintf('home-chart-empty-%s', uniqid())));

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            'section[aria-labelledby="chart-heading"] .empty-state',
            'No account history yet.'
        );
    }

    /**
     * A BRL line and a USD line on the same axis would look comparable when
     * they are not, so each currency gets its own panel and scale.
     */
    public function testHomeKeepsCurrenciesOnSeparateChartPanels(): void
    {
        $client = static::createClient();
        $user = $this->createUser(sprintf('home-chart-curr-%s', uniqid()));
        $client->loginUser($user);

        $date = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-05');
        $brl = $this->seedChartAccount($user, CurrencyEnum::BRL, [[$date, OperationEnum::CREDIT, 500.00]]);
        $usd = $this->seedChartAccount($user, CurrencyEnum::USD, [[$date, OperationEnum::CREDIT, 90.00]]);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.chart-panel'));

        $captions = implode(' | ', $crawler->filter('.chart-panel figcaption')->each(
            static fn (Crawler $caption): string => $caption->text()
        ));
        self::assertStringContainsString('Real (BRL)', $captions);
        self::assertStringContainsString('US Dollar (USD)', $captions);

        // Each panel carries only its own currency's accounts: a BRL value
        // must not leak into the USD panel or vice versa.
        $brlData = $this->panelData($crawler, 'Real (BRL)');
        $usdData = $this->panelData($crawler, 'US Dollar (USD)');

        self::assertSame('BRL', $brlData['currency']);
        self::assertSame('R$', $brlData['symbol']);
        self::assertSame([$brl->getId()], array_column($brlData['datasets'], 'accountId'));

        self::assertSame('USD', $usdData['currency']);
        self::assertSame('US$', $usdData['symbol']);
        self::assertSame([$usd->getId()], array_column($usdData['datasets'], 'accountId'));

        // Both accounts only have activity in the current month, so that is
        // the whole (one point) history.
        $usdValues = $usdData['datasets'][0]['data'];
        self::assertSame(90.0, (float) $usdValues[array_key_last($usdValues)]);
    }

    /**
     * Ranges are offered only when the ledger reaches that far back: five
     * years and nine months of history makes every option available, and the
     * whole history is sent to the browser for the All view.
     */
    public function testHomeOffersOnlyYearRangesCoveredByHistory(): void
    {
        $client = static::createClient();
        $user = $this->createUser(sprintf('home-chart-range-%s', uniqid()));
        $client->loginUser($user);

        $firstOfMonth = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))
            ->modify('first day of this month');
        $start = $firstOfMonth->modify('-65 months');

        $this->seedChartAccount($user, CurrencyEnum::BRL, [
            [$start->format('Y-m-10'), OperationEnum::CREDIT, 100.00],
        ]);

        $crawler = $client->request('GET', '/');

        $ranges = $crawler->filter('.chart-panel')->first()
            ->filter('.chart-range__button')->each(
                static fn (Crawler $button): string => $button->text()
            );
        foreach (['3M', '6M', '1Y', '2Y', '3Y', '5Y', 'All'] as $expected) {
            self::assertContains($expected, $ranges);
        }

        $data = $this->panelData($crawler, 'Real (BRL)');
        self::assertCount(66, $data['labels'], 'One point per month from the first transaction to now');
        self::assertSame(100.0, (float) $data['datasets'][0]['data'][0]);
        self::assertSame(100.0, (float) $data['datasets'][0]['data'][65]);
        self::assertSelectorTextContains('.chart-summary', 'Last 12 months');
    }

    /**
     * Reads the JSON a chart panel hands to its Stimulus controller, selecting
     * the panel by currency title.
     *
     * @return array<string, mixed>
     */
    private function panelData(Crawler $crawler, string $title): array
    {
        $panel = $crawler->filter('.chart-panel')->reduce(
            static fn (Crawler $panel): bool => str_contains($panel->filter('figcaption')->text(), $title)
        );
        self::assertCount(1, $panel);

        $json = $panel->attr('data-balance-chart-data-value');
        self::assertNotNull($json);

        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Creates one account and one transaction per [date, operation, amount]
     * row. Re-fetches the account after clear() so the caller gets a detached
     * entity with a usable id, the same pattern as attachTransactionTo().
     *
     * @param list<array{0: string, 1: OperationEnum, 2: float}> $movements
     */
    private function seedChartAccount(
        User $owner,
        CurrencyEnum $currency,
        array $movements,
        ?string $color = null,
    ): Account {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = $em->getRepository(User::class)->find($owner->getId());
        \assert($user instanceof User);

        $account = new Account();
        $account->setUser($user);
        $account->setName(sprintf('CHART%s', strtoupper(substr(uniqid(), -6))));
        $account->setCurrency($currency);
        if (null !== $color) {
            $account->setColor($color);
        }
        $em->persist($account);
        $em->flush();

        $operationType = $em->getRepository(OperationType::class)->findOneBy([], ['id' => 'ASC']);
        if (!$operationType instanceof OperationType) {
            $operationType = new OperationType();
            $operationType->setType(sprintf('CHARTX%s', strtoupper(substr(uniqid(), -6))));
            $em->persist($operationType);
            $em->flush();
        }

        foreach ($movements as [$date, $operation, $amount]) {
            $transaction = new Transaction();
            $transaction->setUser($user);
            $transaction->setAccount($account);
            $transaction->setOperationType($operationType);
            $transaction->setOperation($operation);
            $transaction->setMovement(new \DateTime($date));
            $transaction->setLiquidation(new \DateTime($date));
            $transaction->setDescription(sprintf('Chart %s', $date));
            $transaction->setTotalPrice($amount);
            $em->persist($transaction);
        }

        $em->flush();
        $em->clear();

        $saved = $em->getRepository(Account::class)->find($account->getId());
        \assert($saved instanceof Account);

        return $saved;
    }

    /**
     * Builds one transaction owned by $owner. Every entity is re-fetched after
     * each flush: $em->clear() detaches anything fetched before it, and a
     * detached association looks like an unmanaged new entity to persist().
     */
    private function attachTransactionTo(
        User $owner,
        string $description,
        OperationEnum $operation = OperationEnum::CREDIT,
        float $totalPrice = 100.00,
    ): void {
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
        $transaction->setOperation($operation);
        $transaction->setMovement(new \DateTime('2026-10-15'));
        $transaction->setLiquidation(new \DateTime('2026-10-17'));
        $transaction->setDescription($description);
        $transaction->setTotalPrice($totalPrice);

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
