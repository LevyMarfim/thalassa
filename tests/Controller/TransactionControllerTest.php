<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\OperationType;
use App\Entity\Ticker;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CurrencyEnum;
use App\Enum\MarketEnum;
use App\Enum\OperationEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
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
        // The create form lives on its own page now; the list only offers a
        // link to it, so the table gets the full card.
        self::assertCount(0, $crawler->filter('form[name="transaction_form"]'));
        self::assertCount(1, $crawler->filter('.dash-header--split a.btn[href="/transactions/new"]'));
        self::assertSelectorTextContains('.tx-table', $transaction->getDescription());
        self::assertSelectorTextContains('.tx-table', '1.234,56');
    }

    /**
     * Direction comes from the operation, not from the stored sign: a debit is
     * money out, so the listing prints it negative — the same rule the balance
     * query sums with. Credits stay positive.
     */
    public function testDebitTotalsRenderNegativeAndCreditTotalsPositive(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $debit = $this->uniqueDescription('SINAL-DEBITO');
        $debitStoredNegative = $this->uniqueDescription('SINAL-DEBITO-NEG');
        $credit = $this->uniqueDescription('SINAL-CREDITO');
        $this->createTransaction($debit, 500.00, null, OperationEnum::DEBIT);
        // The form accepts a signed value too; the operation still wins.
        $this->createTransaction($debitStoredNegative, -500.00, null, OperationEnum::DEBIT);
        $this->createTransaction($credit, 500.00, null, OperationEnum::CREDIT);

        $crawler = $client->request('GET', '/transactions');

        self::assertResponseIsSuccessful();

        $totalOf = static function (Crawler $crawler, string $description): string {
            $row = $crawler->filter('.tx-table tbody tr')->reduce(
                static fn (Crawler $tr): bool => str_contains($tr->text(), $description)
            );
            self::assertCount(1, $row);

            // Qty, Unit price and Total share the class; Total is last.
            return trim($row->filter('td.tx-table__amount')->last()->text());
        };

        self::assertSame('-500,00', $totalOf($crawler, $debit));
        self::assertSame('-500,00', $totalOf($crawler, $debitStoredNegative));
        self::assertSame('500,00', $totalOf($crawler, $credit));
    }

    /**
     * movement/liquidation are DATE columns: the table must print the calendar
     * day the database holds, spelled dd/mmm/yyyy (15/out/2026). It previously
     * passed 'America/Sao_Paulo' to the date filter, which re-interpreted the
     * hydrated UTC midnight as 21:00 the previous day — the listing showed
     * every date one day early, while the edit form (no conversion) and the
     * database were both correct.
     */
    public function testListingShowsTheCalendarDateStoredInTheDatabase(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();
        $description = $this->uniqueDescription('DATA');
        // Fixture dates: movement 2026-10-15, liquidation 2026-10-17.
        $this->createTransaction($description, 10.00);

        $crawler = $client->request('GET', '/transactions');

        self::assertResponseIsSuccessful();

        // Scope the assertions to this row so no neighbouring fixture can
        // satisfy them on its behalf.
        $row = $crawler->filter('.tx-table tbody tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->text(), $description)
        );
        self::assertCount(1, $row);

        $html = $row->html();
        self::assertStringContainsString('15/out/2026', $html);
        self::assertStringContainsString('17/out/2026', $html);
        self::assertStringNotContainsString('14/out/2026', $html);
        self::assertStringNotContainsString('16/out/2026', $html);
        // The numeric form belongs to the form field, not the listing.
        self::assertStringNotContainsString('15/10/2026', $html);
    }

    /**
     * The write path must not shift either: a typed dd/mm/yyyy date is stored
     * as that same calendar day, and the listing then shows it.
     */
    public function testCreateTransactionKeepsTheTypedCalendarDate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $description = $this->uniqueDescription('DATA-CRIADA');

        $crawler = $client->request('GET', '/transactions/new');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[account]' => (string) $data['account']->getId(),
            'transaction_form[movement]' => '20/10/2026',
            'transaction_form[liquidation]' => '22/10/2026',
            'transaction_form[operation]' => 'CREDIT',
            'transaction_form[operationType]' => (string) $data['operationType']->getId(),
            'transaction_form[description]' => $description,
            'transaction_form[totalPrice]' => '10.00',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/transactions');

        $created = $this->findTransaction($description);
        self::assertNotNull($created);
        self::assertSame('2026-10-20', $created->getMovement()?->format('Y-m-d'));
        self::assertSame('2026-10-22', $created->getLiquidation()?->format('Y-m-d'));

        $crawler = $client->followRedirect();
        $row = $crawler->filter('.tx-table tbody tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->text(), $description)
        );
        self::assertCount(1, $row);
        self::assertStringContainsString('20/out/2026', $row->html());
        self::assertStringContainsString('22/out/2026', $row->html());
    }

    public function testTransactionsCanBeFilteredByAccount(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $accountA = $data['account'];

        $descA = $this->uniqueDescription('FILTRO-A');
        $descB = $this->uniqueDescription('FILTRO-B');
        // createTransaction() attaches to the owner's first account (A); B gets
        // its own account so the filter has something to exclude.
        $this->createTransaction($descA, 10.00);
        $this->createAccountWithTransaction($descB, 20.00);

        // Unfiltered: both rows are present and no filter strip is shown.
        $crawler = $client->request('GET', '/transactions');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.tx-filter'));
        self::assertSelectorTextContains('.tx-table', $descA);
        self::assertSelectorTextContains('.tx-table', $descB);

        // Filtered to account A: only its row remains, the strip names the
        // account, and "Show all" leads back to the full list.
        $crawler = $client->request('GET', sprintf('/transactions?account=%d', $accountA->getId()));
        self::assertResponseIsSuccessful();
        $table = $crawler->filter('.tx-table');
        self::assertStringContainsString($descA, $table->html());
        self::assertStringNotContainsString($descB, $table->html());
        self::assertSelectorTextContains('.tx-filter', $accountA->getName());
        self::assertCount(1, $crawler->filter('.tx-filter a[href="/transactions"]'));
        // The notice is part of the header, between the title block and the
        // "Add transaction" button, before the summary cards.
        $notice = $crawler->filter('.dash-header > .tx-filter');
        self::assertCount(1, $notice);
        self::assertSame('Add transaction', trim($notice->nextAll()->filter('a.btn')->text()));
    }

    /**
     * The listing no longer creates transactions: its card is a filter bar,
     * and the create form moved to /transactions/new behind the Add button.
     */
    public function testIndexOffersFiltersAndAnAddButtonInsteadOfTheCreateForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $crawler = $client->request('GET', '/transactions');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[name="transaction_form"]'));
        $filterForm = $crawler->filter('form[name="transaction_filter"]');
        self::assertCount(1, $filterForm);
        // GET keeps a filtered list a shareable URL (and needs no CSRF token).
        self::assertSame('get', strtolower($filterForm->attr('method')));
        self::assertCount(1, $crawler->filter('.dash-header--split a.btn[href="/transactions/new"]'));

        // Every criterion the controller understands has a control.
        foreach (['account', 'operation', 'operationType', 'ticker', 'movementFrom', 'movementTo', 'description', 'sort'] as $field) {
            self::assertCount(
                1,
                $crawler->filter(sprintf('[name="transaction_filter[%s]"]', $field)),
                sprintf('The %s filter is missing.', $field)
            );
        }
    }

    public function testIndexSortsTransactionsByMovementDate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $older = $this->uniqueDescription('OLDER');
        $newer = $this->uniqueDescription('NEWER');
        $this->createTransaction($older, 10.00, null, OperationEnum::CREDIT, '2026-01-10');
        $this->createTransaction($newer, 20.00, null, OperationEnum::CREDIT, '2026-11-10');

        $descriptions = static function (Crawler $crawler): array {
            return $crawler->filter('.tx-table__desc')->each(
                static fn (Crawler $node): string => trim($node->text())
            );
        };

        $crawler = $client->request('GET', '/transactions');
        self::assertSame([$newer, $older], $descriptions($crawler));

        $crawler = $client->request('GET', '/transactions?transaction_filter[sort]=movement_asc');
        self::assertSame([$older, $newer], $descriptions($crawler));
    }

    public function testIndexSortsTransactionsBySignedTotal(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $large = $this->uniqueDescription('LARGE');
        $small = $this->uniqueDescription('SMALL');
        $debit = $this->uniqueDescription('DEBIT');
        $this->createTransaction($large, 300.00, null, OperationEnum::CREDIT);
        $this->createTransaction($small, 50.00, null, OperationEnum::CREDIT);
        $this->createTransaction($debit, 100.00, null, OperationEnum::DEBIT);

        $descriptions = static function (Crawler $crawler): array {
            return $crawler->filter('.tx-table__desc')->each(
                static fn (Crawler $node): string => trim($node->text())
            );
        };

        $crawler = $client->request('GET', '/transactions?transaction_filter[sort]=total_desc');
        self::assertSame([$large, $small, $debit], $descriptions($crawler));

        $crawler = $client->request('GET', '/transactions?transaction_filter[sort]=total_asc');
        self::assertSame([$debit, $small, $large], $descriptions($crawler));
    }

    public function testIndexSummarisesNetMovementPerCurrency(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();

        $this->createTransaction($this->uniqueDescription('BRL-CREDIT'), 100.00, null, OperationEnum::CREDIT);
        $this->createTransaction($this->uniqueDescription('BRL-DEBIT'), 30.00, null, OperationEnum::DEBIT);
        $this->createAccountWithTransaction(
            $this->uniqueDescription('USD-CREDIT'),
            50.00,
            CurrencyEnum::USD,
            OperationEnum::CREDIT,
        );

        $crawler = $client->request('GET', '/transactions');

        $brl = $crawler->filter('.tx-summary__item')->reduce(
            static fn (Crawler $item): bool => str_contains($item->text(), 'BRL net movement')
        );
        self::assertCount(1, $brl);
        self::assertStringContainsString('R$ 70,00', $brl->text());

        $usd = $crawler->filter('.tx-summary__item')->reduce(
            static fn (Crawler $item): bool => str_contains($item->text(), 'USD net movement')
        );
        self::assertCount(1, $usd);
        self::assertStringContainsString('US$ 50,00', $usd->text());

        self::assertSelectorTextContains('.tx-summary__item--muted', '3 transactions in this view');

        // Compact layout + charts: one monthly panel and one value doughnut per
        // currency, all driven by the same Stimulus controller.
        self::assertCount(1, $crawler->filter('.tx-layout'));
        self::assertCount(1, $crawler->filter('.tx-span-8'));
        self::assertCount(1, $crawler->filter('.tx-span-4'));
        $monthly = $crawler->filter('[data-transaction-chart-kind-value="monthly"]');
        self::assertCount(2, $monthly);
        self::assertStringContainsString('BRL', $monthly->first()->attr('data-transaction-chart-data-value'));
        self::assertStringContainsString('USD', $monthly->last()->attr('data-transaction-chart-data-value'));

        $types = $crawler->filter('[data-transaction-chart-kind-value="types"]');
        self::assertCount(2, $types);
        self::assertStringContainsString(
            $data['operationType']->getType(),
            $types->first()->attr('data-transaction-chart-data-value')
        );
    }

    public function testNetMovementSummaryFollowsTheFilters(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();

        $this->createTransaction($this->uniqueDescription('SUM-CREDIT'), 100.00, null, OperationEnum::CREDIT);
        $this->createTransaction($this->uniqueDescription('SUM-DEBIT'), 30.00, null, OperationEnum::DEBIT);
        $this->createAccountWithTransaction(
            $this->uniqueDescription('SUM-USD'),
            50.00,
            CurrencyEnum::USD,
            OperationEnum::CREDIT,
        );

        $summary = static function (Crawler $crawler, string $currency): string {
            $item = $crawler->filter('.tx-summary__item')->reduce(
                static fn (Crawler $node): bool => str_contains($node->text(), $currency.' net movement')
            );
            self::assertCount(1, $item);

            return $item->text();
        };

        $crawler = $client->request('GET', '/transactions');
        self::assertStringContainsString('R$ 70,00', $summary($crawler, 'BRL'));
        self::assertStringContainsString('US$ 50,00', $summary($crawler, 'USD'));
        self::assertSelectorTextContains('.tx-summary__item--muted', '3 transactions in this view');

        // Account filter: the USD panel disappears and the count is scoped.
        $crawler = $client->request('GET', sprintf('/transactions?account=%d', $data['account']->getId()));
        self::assertStringContainsString('R$ 70,00', $summary($crawler, 'BRL'));
        self::assertCount(0, $crawler->filter('.tx-summary__item')->reduce(
            static fn (Crawler $node): bool => str_contains($node->text(), 'USD net movement')
        ));
        self::assertSelectorTextContains('.tx-summary__item--muted', '2 transactions in this view');

        // Operation filter: debits drop out, so the BRL net becomes the credit.
        $crawler = $client->request('GET', '/transactions');
        $form = $crawler->filter('form[name="transaction_filter"]')->form([
            'transaction_filter[operation]' => 'CREDIT',
        ]);
        $crawler = $client->submit($form);
        self::assertStringContainsString('R$ 100,00', $summary($crawler, 'BRL'));
        self::assertSelectorTextContains('.tx-summary__item--muted', '2 transactions in this view');
    }

    public function testChartsFollowTheAccountFilter(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();

        $brl = $this->uniqueDescription('CHART-BRL');
        $usd = $this->uniqueDescription('CHART-USD');
        $this->createTransaction($brl, 100.00, null, OperationEnum::CREDIT, '2026-10-10');
        $this->createAccountWithTransaction($usd, 50.00, CurrencyEnum::USD, OperationEnum::CREDIT);

        // Unfiltered: both currencies get their own movement/cash-flow pair and
        // value doughnut, so BRL and USD are never added together.
        $unfiltered = $client->request('GET', '/transactions');
        self::assertCount(2, $unfiltered->filter('[data-transaction-chart-kind-value="monthly"]'));
        self::assertCount(2, $unfiltered->filter('[data-transaction-chart-kind-value="cashflow"]'));
        self::assertSame(100.0, (float) array_sum($this->chartData($unfiltered, 'cashflow', 'BRL')['net']));
        self::assertSame(50.0, (float) array_sum($this->chartData($unfiltered, 'cashflow', 'USD')['net']));
        self::assertSame(100.0, (float) array_sum($this->chartData($unfiltered, 'types', 'BRL')['values']));
        self::assertSame(50.0, (float) array_sum($this->chartData($unfiltered, 'types', 'USD')['values']));

        // The legacy ?account= link must narrow the charts too, not just the
        // table above them.
        $filtered = $client->request('GET', sprintf('/transactions?account=%d', $data['account']->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $filtered->filter('[data-transaction-chart-kind-value="monthly"]'));
        self::assertCount(1, $filtered->filter('[data-transaction-chart-kind-value="cashflow"]'));
        $monthly = $this->chartData($filtered, 'monthly', 'BRL');
        self::assertSame(100.0, (float) array_sum($monthly['credits']));
        self::assertSame(0.0, (float) array_sum($monthly['debits']));

        $cashFlow = $this->chartData($filtered, 'cashflow', 'BRL');
        self::assertSame(100.0, (float) array_sum($cashFlow['net']));

        $types = $this->chartData($filtered, 'types', 'BRL');
        self::assertSame(100.0, (float) array_sum($types['values']));
        self::assertSame('Filtered', $types['period']);
    }

    public function testCashFlowChartUsesSignedMonthlyNet(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $this->createTransaction($this->uniqueDescription('FLOW-CREDIT'), 100.00, null, OperationEnum::CREDIT, '2026-10-10');
        $this->createTransaction($this->uniqueDescription('FLOW-DEBIT'), 30.00, null, OperationEnum::DEBIT, '2026-10-15');
        $this->createTransaction($this->uniqueDescription('FLOW-OLD-DEBIT'), 40.00, null, OperationEnum::DEBIT, '2026-09-10');

        $crawler = $client->request('GET', '/transactions');
        $cashFlow = $this->chartData($crawler, 'cashflow', 'BRL');
        $net = array_map('floatval', $cashFlow['net']);

        self::assertContains(-40.0, $net, 'A debit-only month must be negative');
        self::assertContains(70.0, $net, 'A mixed month is credits minus debits');
        self::assertSame(30.0, (float) array_sum($net));
    }

    public function testTransactionsChartsShareOnePeriodController(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();
        $this->createTransaction($this->uniqueDescription('PERIOD-CHART'), 100.00, null, OperationEnum::CREDIT, '2026-10-10');

        $crawler = $client->request('GET', '/transactions');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.tx-page[data-controller="chart-period"]'));

        foreach (['monthly', 'cashflow'] as $kind) {
            $panels = $crawler->filter(sprintf('[data-transaction-chart-kind-value="%s"]', $kind));
            self::assertGreaterThan(0, $panels->count());
            self::assertGreaterThan(0, $panels->first()->filter('.chart-range__button')->count());
        }
    }

    public function testMonthlyChartWindowFollowsTheMovementDateFilter(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $january = $this->uniqueDescription('CHART-JAN');
        $november = $this->uniqueDescription('CHART-NOV');
        $this->createTransaction($january, 100.00, null, OperationEnum::CREDIT, '2026-01-10');
        $this->createTransaction($november, 200.00, null, OperationEnum::CREDIT, '2026-11-10');

        $crawler = $client->request('GET', '/transactions', [
            'transaction_filter' => [
                'movementFrom' => '01/01/2026',
                'movementTo' => '31/01/2026',
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tx-table', $january);
        self::assertSelectorTextNotContains('.tx-table', $november);

        $monthly = $this->chartData($crawler, 'monthly', 'BRL');
        // The full unfiltered history reaches back to jan/26, but the date
        // filter caps the chart at exactly the requested month.
        self::assertSame(['jan/26'], $monthly['labels']);
        self::assertSame('jan/26', $monthly['period']);
        self::assertSame(0, $monthly['defaultRange']);
        self::assertSame([100.0], array_map('floatval', $monthly['credits']));
        self::assertSame([0.0], array_map('floatval', $monthly['debits']));

        $panel = $crawler->filter('[data-transaction-chart-kind-value="monthly"]')->first();
        self::assertSame(['All'], $this->rangeLabels($panel));
    }

    public function testMonthlyChartDefaultsToTheRangeCoveringOldActivity(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $old = $this->uniqueDescription('CHART-OLD');
        $this->createTransaction($old, 90.00, null, OperationEnum::CREDIT, '2024-03-10');

        // Stale activity no longer falls back to All: the chart opens on the
        // shortest preset that still contains the last movement.
        $crawler = $client->request('GET', '/transactions');
        self::assertResponseIsSuccessful();

        $monthly = $this->chartData($crawler, 'monthly', 'BRL');
        $expected = $this->expectedDefaultRange('2024-03-10');
        self::assertSame('mar/24', $monthly['labels'][0]);
        self::assertGreaterThan(12, \count($monthly['labels']));
        self::assertSame($expected, $monthly['defaultRange']);
        self::assertSame(90.0, (float) array_sum($monthly['credits']));

        $panel = $crawler->filter('[data-transaction-chart-kind-value="monthly"]')->first();
        $label = $this->rangeLabel($expected);
        self::assertContains($label, $this->rangeLabels($panel));
        self::assertSame([$label], $this->pressedRanges($panel));
        self::assertStringContainsString(
            0 === $expected ? 'All time (' : 'Last '.$expected.' months',
            $panel->filter('.chart-summary')->text()
        );
    }

    public function testMonthlyChartDefaultsToFiveYearsForJun2022Activity(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $this->createTransaction($this->uniqueDescription('CHART-2022'), 75.00, null, OperationEnum::CREDIT, '2022-06-10');

        $crawler = $client->request('GET', '/transactions');
        $monthly = $this->chartData($crawler, 'monthly', 'BRL');
        self::assertSame('jun/22', $monthly['labels'][0]);
        self::assertSame($this->expectedDefaultRange('2022-06-10'), $monthly['defaultRange']);

        // The 5Y button must be offered even when the ledger spans slightly
        // fewer than 60 months, otherwise its preselected state is impossible.
        $panel = $crawler->filter('[data-transaction-chart-kind-value="monthly"]')->first();
        $label = $this->rangeLabel($monthly['defaultRange']);
        self::assertContains($label, $this->rangeLabels($panel));
        self::assertSame([$label], $this->pressedRanges($panel));
    }

    public function testMonthlyChartFallsBackToAllOnlyForVeryOldActivity(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $this->createTransaction($this->uniqueDescription('CHART-2019'), 25.00, null, OperationEnum::CREDIT, '2019-01-10');

        $crawler = $client->request('GET', '/transactions');
        $monthly = $this->chartData($crawler, 'monthly', 'BRL');
        self::assertSame(0, $monthly['defaultRange']);

        $panel = $crawler->filter('[data-transaction-chart-kind-value="monthly"]')->first();
        self::assertSame(['All'], $this->pressedRanges($panel));
        self::assertStringContainsString('All time (', $panel->filter('.chart-summary')->text());
    }

    public function testMonthlyChartStartsOnOneYearWhenRecentActivityExists(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $this->createTransaction($this->uniqueDescription('CHART-OLD'), 40.00, null, OperationEnum::CREDIT, '2024-03-10');
        $this->createTransaction($this->uniqueDescription('CHART-RECENT'), 60.00, null, OperationEnum::CREDIT, '2026-10-10');

        $crawler = $client->request('GET', '/transactions');
        self::assertResponseIsSuccessful();

        $monthly = $this->chartData($crawler, 'monthly', 'BRL');
        $today = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $monthNames = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        $currentMonth = $monthNames[(int) $today->format('n') - 1].'/'.$today->format('y');
        self::assertSame(12, $monthly['defaultRange']);
        self::assertContains('out/26', \array_slice($monthly['labels'], -12));
        self::assertSame($currentMonth, $monthly['labels'][array_key_last($monthly['labels'])]);
        self::assertSame(100.0, (float) array_sum($monthly['credits']));

        $panel = $crawler->filter('[data-transaction-chart-kind-value="monthly"]')->first();
        self::assertSame(['1Y'], $this->pressedRanges($panel));
        self::assertStringContainsString('Last 12 months', $panel->filter('.chart-summary')->text());
    }

    public function testNewPageRendersTheCreateForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $crawler = $client->request('GET', '/transactions/new');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[name="transaction_form"]'));
        self::assertCount(1, $crawler->filter('.card__back a[href="/transactions"]'));
        self::assertCount(2, $crawler->filter('.tx-form-section'));
        self::assertSelectorTextContains('.tx-form-section legend', 'Essential details');
        self::assertSelectorTextContains('.tx-form-section--optional legend', 'Optional details');
    }

    public function testNewTransactionListsOperationTypesAlphabetically(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        // Insert in deliberately non-alphabetical order, with mixed case. The
        // suffix keeps the fixture unique when the test DB is not recreated.
        $em = $this->entityManager();
        $suffix = strtoupper(substr(uniqid(), -5));
        $names = ["zebra transfer $suffix", "Alpha PIX $suffix", "beta bill $suffix"];
        foreach ($names as $name) {
            $type = new OperationType();
            $type->setType($name);
            $em->persist($type);
        }
        $em->flush();
        $em->clear();

        $crawler = $client->request('GET', '/transactions/new');

        $labels = $crawler->filter('select[name="transaction_form[operationType]"] option')
            ->each(static fn (Crawler $option): string => trim($option->text()));
        // The first option is the "Select…" placeholder, not a type.
        array_shift($labels);
        foreach ($names as $name) {
            self::assertContains($name, $labels);
        }

        $expected = $labels;
        usort($expected, 'strcasecmp');
        self::assertSame($expected, $labels);
    }

    public function testAnonymousNewRedirectsToLogin(): void
    {
        static::createClient()->request('GET', '/transactions/new');

        self::assertResponseRedirects('/login');
    }

    public function testFilterFormNarrowsByAccount(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();

        $descA = $this->uniqueDescription('FILTRO-FORM-A');
        $descB = $this->uniqueDescription('FILTRO-FORM-B');
        $this->createTransaction($descA, 10.00);
        $this->createAccountWithTransaction($descB, 20.00);

        $crawler = $client->request('GET', '/transactions');
        $form = $crawler->filter('form[name="transaction_filter"]')->form([
            'transaction_filter[account]' => (string) $data['account']->getId(),
        ]);
        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('.tx-table');
        self::assertStringContainsString($descA, $table->html());
        self::assertStringNotContainsString($descB, $table->html());

        // The applied value is still selected, so the URL is reproducible.
        self::assertSame(
            (string) $data['account']->getId(),
            $crawler->filter('form[name="transaction_filter"]')->form()->get('transaction_filter[account]')->getValue()
        );
    }

    public function testFilterFormNarrowsByOperationAndMovementDateRange(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $debit = $this->uniqueDescription('FILTRO-DEBITO');
        $credit = $this->uniqueDescription('FILTRO-CREDITO');
        $this->createTransaction($debit, 10.00, null, OperationEnum::DEBIT, '2026-10-15');
        $this->createTransaction($credit, 20.00, null, OperationEnum::CREDIT, '2026-11-20');

        // Operation: only the credit row survives.
        $crawler = $client->request('GET', '/transactions');
        $form = $crawler->filter('form[name="transaction_filter"]')->form([
            'transaction_filter[operation]' => 'CREDIT',
        ]);
        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('.tx-table');
        self::assertStringContainsString($credit, $table->html());
        self::assertStringNotContainsString($debit, $table->html());

        // Date range: both bounds inclusive, so 15/10 matches 14..16 but the
        // November row does not.
        $crawler = $client->request('GET', '/transactions');
        $form = $crawler->filter('form[name="transaction_filter"]')->form([
            'transaction_filter[movementFrom]' => '14/10/2026',
            'transaction_filter[movementTo]' => '16/10/2026',
        ]);
        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('.tx-table');
        self::assertStringContainsString($debit, $table->html());
        self::assertStringNotContainsString($credit, $table->html());
    }

    public function testFilterFormNarrowsByDescriptionCaseInsensitively(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $match = $this->uniqueDescription('FILTRO-TEXTO');
        $other = $this->uniqueDescription('OUTRO-TEXTO');
        $this->createTransaction($match, 10.00);
        $this->createTransaction($other, 20.00);

        $crawler = $client->request('GET', '/transactions');
        $form = $crawler->filter('form[name="transaction_filter"]')->form([
            'transaction_filter[description]' => 'filtro-texto',
        ]);
        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('.tx-table');
        self::assertStringContainsString($match, $table->html());
        self::assertStringNotContainsString($other, $table->html());
    }

    public function testFilterFormNarrowsByTicker(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $description = $this->uniqueDescription('FILTRO-TICKER');
        $this->createTransaction($description, 10.00);

        // A ticker that exists in the dropdown but is on none of this user's
        // rows: the filter must yield the empty state, not the row.
        $em = $this->entityManager();
        $unused = new Ticker();
        $unused->setTicker(sprintf('ZZ%s', strtoupper(substr(uniqid(), -5))));
        $unused->setName('Unused ticker');
        $em->persist($unused);
        $em->flush();

        $crawler = $client->request('GET', '/transactions');
        $form = $crawler->filter('form[name="transaction_filter"]')->form([
            'transaction_filter[ticker]' => (string) $unused->getId(),
        ]);
        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.tx-table'));
        self::assertCount(1, $crawler->filter('.tx-list .empty-state'));
        self::assertSelectorTextContains('.tx-list .empty-state', 'No transactions match these filters.');
        self::assertCount(1, $crawler->filter('.tx-list .empty-state a[href="/transactions"]'));
        self::assertStringNotContainsString($description, $crawler->text());
    }

    public function testFilteredViewPreselectsTheAccountInTheForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $accountA = $data['account'];

        $crawler = $client->request('GET', sprintf('/transactions?account=%d', $accountA->getId()));
        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter(sprintf('.dash-header--split a.btn[href="/transactions/new?account=%d"]', $accountA->getId()))
        );

        $crawler = $client->request('GET', sprintf('/transactions/new?account=%d', $accountA->getId()));

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="transaction_form"]')->form();
        self::assertSame((string) $accountA->getId(), $form->get('transaction_form[account]')->getValue());
        // Leaving the form the same way preserves the account filter.
        self::assertCount(
            1,
            $crawler->filter(sprintf('.card__back a[href="/transactions?account=%d"]', $accountA->getId()))
        );
    }

    public function testCreatingFromFilteredViewKeepsTheFilter(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $accountA = $data['account'];
        $description = $this->uniqueDescription('FILTRO-CRIADO');

        $crawler = $client->request('GET', sprintf('/transactions/new?account=%d', $accountA->getId()));
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[account]' => (string) $accountA->getId(),
            'transaction_form[movement]' => '20/10/2026',
            'transaction_form[liquidation]' => '22/10/2026',
            'transaction_form[operation]' => 'CREDIT',
            'transaction_form[operationType]' => (string) $data['operationType']->getId(),
            'transaction_form[description]' => $description,
            'transaction_form[totalPrice]' => '99.90',
        ]);
        $client->submit($form);

        // Back to the filtered view, not the full list.
        self::assertResponseRedirects(sprintf('/transactions?account=%d', $accountA->getId()));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.tx-table', $description);

        $created = $this->findTransaction($description);
        self::assertNotNull($created);
        self::assertSame($accountA->getId(), $created->getAccount()?->getId());
    }

    public function testAccountFilterRejectsForeignAccounts(): void
    {
        $client = static::createClient();
        $me = $this->createUser();
        // createAccountWithTransaction() attaches to currentUser, so the second
        // user must be the one loaded when it runs.
        $this->createUser();
        $foreign = $this->createAccountWithTransaction($this->uniqueDescription('FILTRO-ALHEIO'), 5.00);

        $client->loginUser($me);
        $client->request('GET', sprintf('/transactions?account=%d', $foreign->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAccountFilterRejectsUnknownAndMalformedAccounts(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        // Unknown id, non-numeric id, empty value: none can name an account
        // of this user, so every one is a 404.
        $client->request('GET', '/transactions?account=999999');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/transactions?account=nada');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/transactions?account=');
        self::assertResponseStatusCodeSame(404);

        // An array never reaches the controller at all: the kernel rejects
        // non-scalar request input with a 400 before any action runs. (The
        // is_string() guard in the controller is defense in depth behind it.)
        $client->request('GET', '/transactions?account[]=1');
        self::assertResponseStatusCodeSame(400);
    }

    public function testTransactionsPagesSpanFullWidth(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/transactions');

        self::assertResponseIsSuccessful();
        // The block sits inside main's class attribute; a missing leading space
        // would render "dash-maindash-main--wide" and never match.
        self::assertCount(1, $crawler->filter('main.dash-main.dash-main--wide'));
    }

    public function testEditTransactionSpansFullWidth(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();
        $transaction = $this->createTransaction($this->uniqueDescription('EDIT'), 10.00);
        \assert($transaction instanceof Transaction);

        $crawler = $client->request('GET', sprintf('/transactions/%d/edit', $transaction->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('main.dash-main.dash-main--wide'));
    }

    public function testDashboardSpansFullWidth(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // Full window is the layout default now, so the dashboard spans too.
        self::assertCount(1, $crawler->filter('main.dash-main.dash-main--wide'));
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

        $crawler = $client->request('GET', '/transactions/new');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[account]' => (string) $data['account']->getId(),
            'transaction_form[movement]' => '20/10/2026',
            'transaction_form[liquidation]' => '22/10/2026',
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
        self::assertSelectorTextContains('.alert--success', 'Transaction added.');
        // The flash is rendered as an auto-dismissing toast with a close button.
        self::assertCount(1, $client->getCrawler()->filter('.flash-toasts[data-controller="flash"]'));
        self::assertCount(1, $client->getCrawler()->filter('.flash-toast[data-flash-target="toast"]'));
        self::assertCount(1, $client->getCrawler()->filter('.flash-toast .flash-toast__close[data-action="flash#dismiss"]'));
        self::assertCount(1, $client->getCrawler()->filter('.flash-toast .flash-toast__progress .flash-toast__progress-bar'));

        $created = $this->findTransaction($description);
        self::assertNotNull($created);
        self::assertSame($user->getId(), $created->getUser()?->getId());
        self::assertSame(OperationEnum::CREDIT, $created->getOperation());
        self::assertEqualsWithDelta(2500.75, $created->getTotalPrice(), 0.001);
    }

    /**
     * The form takes a plain magnitude ("500.00"); the write path stores the
     * direction in the column so any SUM(total_price) is correct by itself.
     */
    public function testCreateDebitTransactionStoresNegativeTotal(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $description = $this->uniqueDescription('COMPRA');

        $crawler = $client->request('GET', '/transactions/new');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[account]' => (string) $data['account']->getId(),
            'transaction_form[movement]' => '20/10/2026',
            'transaction_form[liquidation]' => '22/10/2026',
            'transaction_form[operation]' => 'DEBIT',
            'transaction_form[operationType]' => (string) $data['operationType']->getId(),
            'transaction_form[ticker]' => (string) $data['ticker']->getId(),
            'transaction_form[description]' => $description,
            'transaction_form[totalPrice]' => '500.00',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/transactions');

        $created = $this->findTransaction($description);
        self::assertNotNull($created);
        self::assertSame(OperationEnum::DEBIT, $created->getOperation());
        self::assertEqualsWithDelta(-500.00, $created->getTotalPrice(), 0.001);
    }

    /**
     * On the create form the liquidation date is optional: leaving it blank
     * means "same day as the movement", not a validation error.
     */
    public function testCreateTransactionFillsBlankLiquidationFromMovement(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $description = $this->uniqueDescription('LIQUIDACAO');

        $crawler = $client->request('GET', '/transactions/new');
        self::assertSelectorTextContains('label[for="transaction_form_liquidation"]', 'optional');
        self::assertSelectorTextContains('#transaction_form_liquidation_help', 'movement date');

        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[account]' => (string) $data['account']->getId(),
            'transaction_form[movement]' => '20/10/2026',
            'transaction_form[liquidation]' => '',
            'transaction_form[operation]' => 'CREDIT',
            'transaction_form[operationType]' => (string) $data['operationType']->getId(),
            'transaction_form[description]' => $description,
            'transaction_form[totalPrice]' => '10.00',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/transactions');

        $created = $this->findTransaction($description);
        self::assertNotNull($created);
        self::assertSame('2026-10-20', $created->getMovement()?->format('Y-m-d'));
        self::assertSame('2026-10-20', $created->getLiquidation()?->format('Y-m-d'));
    }

    /**
     * An explicit liquidation date is never overwritten by the fallback.
     */
    public function testCreateTransactionKeepsAnExplicitLiquidationDate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $description = $this->uniqueDescription('LIQUIDACAO-DATA');

        $crawler = $client->request('GET', '/transactions/new');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[account]' => (string) $data['account']->getId(),
            'transaction_form[movement]' => '20/10/2026',
            'transaction_form[liquidation]' => '25/10/2026',
            'transaction_form[operation]' => 'CREDIT',
            'transaction_form[operationType]' => (string) $data['operationType']->getId(),
            'transaction_form[description]' => $description,
            'transaction_form[totalPrice]' => '10.00',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/transactions');

        $created = $this->findTransaction($description);
        self::assertNotNull($created);
        self::assertSame('2026-10-25', $created->getLiquidation()?->format('Y-m-d'));
    }

    public function testCreateTransactionWithoutTicker(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $description = $this->uniqueDescription('CONTA');

        $crawler = $client->request('GET', '/transactions/new');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[account]' => (string) $data['account']->getId(),
            'transaction_form[movement]' => '20/10/2026',
            'transaction_form[liquidation]' => '22/10/2026',
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

    /**
     * The four cost-basis fields added to Transaction must round-trip through
     * the form and land in the table. They are all optional, so this test also
     * pins that supplying them is what persists them — not a side effect.
     */
    public function testCreateTransactionPersistsCostBasisFields(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $description = $this->uniqueDescription('FRAC');

        $crawler = $client->request('GET', '/transactions/new');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[account]' => (string) $data['account']->getId(),
            'transaction_form[movement]' => '20/10/2026',
            'transaction_form[liquidation]' => '22/10/2026',
            'transaction_form[operation]' => 'CREDIT',
            'transaction_form[operationType]' => (string) $data['operationType']->getId(),
            'transaction_form[ticker]' => (string) $data['ticker']->getId(),
            'transaction_form[amount]' => '10',
            'transaction_form[unitPrice]' => '25.50',
            'transaction_form[market]' => 'FRACTIONAL',
            'transaction_form[invoice]' => '123456',
            'transaction_form[description]' => $description,
            'transaction_form[totalPrice]' => '255.00',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/transactions');
        $created = $this->findTransaction($description);
        self::assertNotNull($created);
        self::assertSame(10, $created->getAmount());
        self::assertEqualsWithDelta(25.50, $created->getUnitPrice() ?? 0.0, 0.001);
        self::assertSame(MarketEnum::FRACTIONAL, $created->getMarket());
        self::assertSame(123456, $created->getInvoice());

        // And they must render, not just persist.
        $crawler = $client->request('GET', '/transactions');
        $row = $crawler->filter('tr')->filter(sprintf('td:contains("%s")', $description))->ancestors()->last();
        self::assertStringContainsString('Fracionário', $row->html());
    }

    /**
     * Omitting the optional cost-basis fields must store NULL rather than
     * failing validation or defaulting to zero — a zero quantity would be a
     * real (wrong) fact about the trade, not an absent one.
     */
    public function testCostBasisFieldsAreOptional(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $description = $this->uniqueDescription('SEMFRAC');

        $crawler = $client->request('GET', '/transactions/new');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[account]' => (string) $data['account']->getId(),
            'transaction_form[movement]' => '20/10/2026',
            'transaction_form[liquidation]' => '22/10/2026',
            'transaction_form[operation]' => 'CREDIT',
            'transaction_form[operationType]' => (string) $data['operationType']->getId(),
            'transaction_form[ticker]' => '',
            'transaction_form[description]' => $description,
            'transaction_form[totalPrice]' => '10.00',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/transactions');
        $created = $this->findTransaction($description);
        self::assertNotNull($created);
        self::assertNull($created->getAmount());
        self::assertNull($created->getUnitPrice());
        self::assertNull($created->getMarket());
        self::assertNull($created->getInvoice());
    }

    /**
     * An invalid market must be rejected by the form rather than reaching the
     * enum coercion (which would be a 500, not a 422).
     *
     * Submitted as raw parameters rather than through Crawler::form(): the
     * crawler validates <option> values against the rendered list and refuses
     * to build the request at all, so it cannot express this case.
     */
    public function testCreateTransactionRejectsUnknownMarket(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $before = $this->countTransactions();

        $crawler = $client->request('GET', '/transactions/new');
        $form = $crawler->filter('form[name="transaction_form"]')->form();

        // Crawler::ChoiceFormField refuses to hold a value that isn't one of
        // the rendered <option>s, so the forged value is injected into the
        // submitted parameters after the form has been read. Everything else —
        // including the CSRF token — still comes from the rendered form.
        $params = $form->getPhpValues();
        $params['transaction_form']['market'] = 'NOT_A_MARKET';
        $params['transaction_form']['description'] = $this->uniqueDescription('MAU');

        $client->request('POST', $form->getUri(), $params);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->countTransactions());
    }

    public function testCreateTransactionRejectsBlankRequiredFields(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $before = $this->countTransactions();

        $crawler = $client->request('GET', '/transactions/new');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[description]' => '',
            'transaction_form[totalPrice]' => '',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countTransactions());
    }

    /**
     * The date fields must render as plain text inputs pinned to a dd/mm/yyyy
     * shape — never as <input type="date">, whose format the browser derives
     * from its own locale (mm/dd/yyyy on en-US) and which no server-side
     * option can override. The placeholder mirrors the visible picker
     * format: 06/jan/2022.
     */
    public function testDateFieldsAreTextInputsWithBrazilianFormat(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $crawler = $client->request('GET', '/transactions/new');

        self::assertResponseIsSuccessful();

        foreach (['movement', 'liquidation'] as $field) {
            $input = $crawler->filter(sprintf('input[name="transaction_form[%s]"]', $field));
            self::assertCount(1, $input);
            self::assertSame('text', $input->attr('type'), sprintf('The %s field must not be a browser-format date input.', $field));
            self::assertSame('dd/mmm/yyyy', $input->attr('placeholder'));
            self::assertSame('10', $input->attr('maxlength'));
        }
    }

    /**
     * The same fields are progressively enhanced with the flatpickr popup
     * calendar (Stimulus date_picker controller): JS through the importmap,
     * theme through a stylesheet link, hook on both inputs.
     */
    public function testDateFieldsAreEnhancedWithDatePicker(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $crawler = $client->request('GET', '/transactions/new');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('input[data-controller="date-picker"]'));
        self::assertStringContainsString('flatpickr', $crawler->filter('script[type="importmap"]')->text());
        // Scope to rel=stylesheet: the modulepreload for the JS also matches
        // a bare href* selector (lesson from an earlier round).
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="flatpickr"]'));
    }

    /**
     * A date in the old Y-m-d order (or any other) must be rejected by the
     * transformer with a form error, not stored or silently reinterpreted
     * (2026-10-20 would be October 20 under d/m/Y parsing of a 2-digit month).
     */
    public function testCreateTransactionRejectsDateInWrongFormat(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $before = $this->countTransactions();

        $crawler = $client->request('GET', '/transactions/new');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[account]' => (string) $data['account']->getId(),
            'transaction_form[movement]' => '2026-10-20',
            'transaction_form[liquidation]' => '22/10/2026',
            'transaction_form[operation]' => 'CREDIT',
            'transaction_form[operationType]' => (string) $data['operationType']->getId(),
            'transaction_form[description]' => $this->uniqueDescription('DATAERR'),
            'transaction_form[totalPrice]' => '10.00',
        ]);
        $crawler = $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countTransactions());
        // form_errors() renders a bare <ul><li> right after the input (no
        // custom form theme), inside the field's .form-field wrapper.
        self::assertStringContainsString(
            'dd/mm/yyyy',
            $crawler->filter('input[name="transaction_form[movement]"]')->ancestors()->first()->filter('ul li')->text()
        );
    }

    public function testEditPageRendersPrefilled(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();
        $transaction = $this->createTransaction($this->uniqueDescription('COMPRA'), 1234.56);

        $crawler = $client->request('GET', sprintf('/transactions/%d/edit', $transaction->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.tx-form-section'));
        self::assertSelectorTextContains('.tx-edit__meta', $transaction->getAccount()?->getName() ?? '');
        self::assertSame(
            $transaction->getDescription(),
            $crawler->filter('input[name="transaction_form[description]"]')->attr('value')
        );
        // The server renders its numeric wire value (the picker re-displays
        // it as 15/out/2026 client-side; the fixture uses 2026-10-15/17).
        self::assertSame('15/10/2026', $crawler->filter('input[name="transaction_form[movement]"]')->attr('value'));
        self::assertSame('17/10/2026', $crawler->filter('input[name="transaction_form[liquidation]"]')->attr('value'));
        // The fixture is a debit, stored negative; the form shows what is stored
        // (localised with a comma, as NumberType renders it).
        self::assertSame('-1234,56', $crawler->filter('input[name="transaction_form[totalPrice]"]')->attr('value'));
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
        // The fixture is a DEBIT, so the write path stores the total as a
        // negative amount (credits stay positive).
        self::assertSame(OperationEnum::DEBIT, $updated->getOperation());
        self::assertEqualsWithDelta(-1300.00, $updated->getTotalPrice(), 0.001);
        self::assertNull($this->findTransaction($transaction->getDescription()));
    }

    public function testEditKeepsTheAccountFilter(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();

        $visible = $this->uniqueDescription('EDIT-KEEP');
        $hidden = $this->uniqueDescription('EDIT-HIDE');
        $this->createTransaction($visible, 10.00);
        $this->createAccountWithTransaction($hidden, 20.00);

        $filteredUrl = sprintf('/transactions?account=%d', $data['account']->getId());
        $crawler = $client->request('GET', $filteredUrl);
        self::assertSelectorTextContains('.tx-table', $visible);
        self::assertSelectorTextNotContains('.tx-table', $hidden);

        $editLink = $crawler->filter('.tx-table a.type-list__edit')->link();
        self::assertStringContainsString(sprintf('account=%d', $data['account']->getId()), $editLink->getUri());

        $crawler = $client->click($editLink);
        self::assertResponseIsSuccessful();
        $edited = $this->uniqueDescription('EDIT-SAVED');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[description]' => $edited,
        ]);
        self::assertStringContainsString(sprintf('account=%d', $data['account']->getId()), $form->getUri());
        $client->submit($form);

        self::assertResponseRedirects($filteredUrl);
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.tx-filter', $data['account']->getName());
        self::assertSelectorTextContains('.tx-table', $edited);
        self::assertSelectorTextNotContains('.tx-table', $hidden);
    }

    public function testEditKeepsSubmittedFilterFormValues(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->seedTransactionData();

        $credit = $this->uniqueDescription('EDIT-CREDIT');
        $debit = $this->uniqueDescription('EDIT-DEBIT');
        $this->createTransaction($credit, 20.00, null, OperationEnum::CREDIT, '2026-11-20');
        $this->createTransaction($debit, 10.00, null, OperationEnum::DEBIT, '2026-10-15');

        $crawler = $client->request('GET', '/transactions');
        $filterForm = $crawler->filter('form[name="transaction_filter"]')->form([
            'transaction_filter[operation]' => 'CREDIT',
        ]);
        $crawler = $client->submit($filterForm);
        self::assertSelectorTextContains('.tx-table', $credit);
        self::assertSelectorTextNotContains('.tx-table', $debit);

        $editLink = $crawler->filter('.tx-table a.type-list__edit')->link();
        self::assertStringContainsString('transaction_filter', $editLink->getUri());
        self::assertStringContainsString('CREDIT', $editLink->getUri());

        $crawler = $client->click($editLink);
        $edited = $this->uniqueDescription('EDIT-CREDIT-SAVED');
        $form = $crawler->filter('form[name="transaction_form"]')->form([
            'transaction_form[description]' => $edited,
        ]);
        self::assertStringContainsString('transaction_filter', $form->getUri());
        self::assertStringContainsString('CREDIT', $form->getUri());
        $client->submit($form);

        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertIsString($location);
        self::assertStringContainsString('transaction_filter', $location);
        self::assertStringContainsString('CREDIT', $location);

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.tx-table', $edited);
        self::assertSelectorTextNotContains('.tx-table', $debit);
        $restoredFilter = $crawler->filter('form[name="transaction_filter"]')->form();
        self::assertSame('CREDIT', $restoredFilter['transaction_filter[operation]']->getValue());
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
     * @return array{operationType: OperationType, ticker: Ticker, account: Account}
     */
    private function seedTransactionData(): array
    {
        $em = $this->entityManager();

        $operationType = new OperationType();
        $operationType->setType(sprintf('OPT%s', strtoupper(substr(uniqid(), -5))));

        $ticker = new Ticker();
        $ticker->setTicker(sprintf('T%s', strtoupper(substr(uniqid(), -6))));
        // name is NOT NULL, so the fixture needs one or the INSERT fails.
        $ticker->setName(sprintf('Name %s', $ticker->getTicker()));

        // account_id is NOT NULL, and transactions must belong to their owner.
        // Re-fetch the user: createUser() clears the EM, which detaches it, and
        // a detached reference looks like an unmanaged new entity to persist().
        $owner = $em->getRepository(User::class)->find($this->currentUser?->getId());
        \assert($owner instanceof User);
        $account = new Account();
        $account->setUser($owner);
        $account->setName(sprintf('ACC%s', strtoupper(substr(uniqid(), -6))));
        $account->setCurrency(CurrencyEnum::BRL);

        $em->persist($operationType);
        $em->persist($ticker);
        $em->persist($account);
        $em->flush();
        $em->clear();

        return ['operationType' => $operationType, 'ticker' => $ticker, 'account' => $account];
    }

    /**
     * A second account for the current user, with one transaction attached so
     * the filtered view has rows of its own to show. Everything is flushed in
     * a single unit of work: a clear() between the two persists would leave
     * the association pointing at a detached entity.
     */
    private function createAccountWithTransaction(
        string $description,
        float $totalPrice,
        CurrencyEnum $currency = CurrencyEnum::BRL,
        OperationEnum $operation = OperationEnum::DEBIT,
    ): Account {
        $em = $this->entityManager();

        $owner = $em->getRepository(User::class)->find($this->currentUser?->getId());
        \assert($owner instanceof User);
        $operationType = $em->getRepository(OperationType::class)->findOneBy([], ['id' => 'DESC']);
        \assert($operationType instanceof OperationType);
        $ticker = $em->getRepository(Ticker::class)->findOneBy([], ['id' => 'DESC']);
        \assert($ticker instanceof Ticker);

        $account = new Account();
        $account->setUser($owner);
        $account->setName(sprintf('ACC%s', strtoupper(substr(uniqid(), -6))));
        $account->setCurrency($currency);
        $em->persist($account);

        $transaction = new Transaction();
        $transaction->setUser($owner);
        $transaction->setAccount($account);
        $transaction->setMovement(new \DateTime('2026-10-15'));
        $transaction->setLiquidation(new \DateTime('2026-10-17'));
        $transaction->setOperation($operation);
        $transaction->setOperationType($operationType);
        $transaction->setTicker($ticker);
        $transaction->setDescription($description);
        $transaction->setTotalPrice($totalPrice);

        $em->persist($transaction);
        $em->flush();
        $em->clear();

        return $account;
    }

    private function createTransaction(
        string $description,
        float $totalPrice,
        ?User $owner = null,
        OperationEnum $operation = OperationEnum::DEBIT,
        string $movement = '2026-10-15',
    ): Transaction {
        $em = $this->entityManager();

        $user = $em->getRepository(User::class)->find(($owner ?? $this->currentUser)?->getId());
        \assert($user instanceof User);

        // An account must exist for whoever owns this row. The isolation tests
        // create a second user who has none yet, so mint one rather than
        // assuming the current user's account can be reused. This may clear the
        // EM, so it must run before the fixtures are fetched below.
        $account = $em->getRepository(Account::class)->findOneBy(['user' => $user]);
        if (!$account instanceof Account) {
            $account = new Account();
            $account->setUser($user);
            $account->setName(sprintf('ACC%s', strtoupper(substr(uniqid(), -6))));
            $account->setCurrency(CurrencyEnum::BRL);
            $em->persist($account);
            $em->flush();
            $em->clear();

            // Re-fetch after the clear: both references must stay managed.
            $user = $em->getRepository(User::class)->find($user->getId());
            \assert($user instanceof User);
            $account = $em->getRepository(Account::class)->findOneBy(['user' => $user]);
        }
        \assert($account instanceof Account);

        // Re-fetch the shared fixtures (the EM may have been cleared, which
        // detaches everything) so every reference below is a managed entity.
        $operationType = $em->getRepository(OperationType::class)->findOneBy([], ['id' => 'DESC']);
        $ticker = $em->getRepository(Ticker::class)->findOneBy([], ['id' => 'DESC']);

        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setAccount($account);
        $transaction->setMovement(new \DateTime($movement));
        $transaction->setLiquidation(new \DateTime('2026-10-17'));
        $transaction->setOperation($operation);
        $transaction->setOperationType($operationType);
        $transaction->setTicker($ticker);
        $transaction->setDescription($description);
        $transaction->setTotalPrice($totalPrice);

        $em->persist($transaction);
        $em->flush();
        $em->clear();

        return $transaction;
    }

    /**
     * Reads the JSON handed to a chart's Stimulus controller. A currency can be
     * given for any kind with per-currency panels (monthly and both doughnuts),
     * so a filter that removes a panel cannot be satisfied by another one.
     *
     * @return array<string, mixed>
     */
    private function chartData(Crawler $crawler, string $kind, ?string $currency = null): array
    {
        $panels = $crawler->filter(sprintf('[data-transaction-chart-kind-value="%s"]', $kind));
        if (null !== $currency) {
            $panels = $panels->reduce(
                static fn (Crawler $panel): bool => str_contains(
                    (string) $panel->attr('data-transaction-chart-data-value'),
                    sprintf('"currency":"%s"', $currency)
                )
            );
        }
        self::assertCount(1, $panels);

        $json = $panels->attr('data-transaction-chart-data-value');
        self::assertNotNull($json);

        return json_decode(
            html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            true,
            512,
            \JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return list<string> visible range button labels, in DOM order
     */
    private function rangeLabels(Crawler $panel): array
    {
        return $panel->filter('.chart-range__button')->each(
            static fn (Crawler $button): string => trim($button->text())
        );
    }

    /**
     * @return list<string> labels of the currently pressed range buttons
     */
    private function pressedRanges(Crawler $panel): array
    {
        return $panel->filter('.chart-range__button')
            ->reduce(static fn (Crawler $button): bool => 'true' === $button->attr('aria-pressed'))
            ->each(static fn (Crawler $button): string => trim($button->text()));
    }

    /**
     * Mirrors the chart builder's rule: the shortest preset that still reaches
     * the given last movement, with All as the fallback for older activity.
     */
    private function expectedDefaultRange(string $lastMovement): int
    {
        $timezone = new \DateTimeZone('America/Sao_Paulo');
        $last = (new \DateTimeImmutable($lastMovement, $timezone))->modify('first day of this month');
        $current = (new \DateTimeImmutable('now', $timezone))->modify('first day of this month');
        $monthsAgo = ((int) $current->format('Y') - (int) $last->format('Y')) * 12
            + ((int) $current->format('n') - (int) $last->format('n'));

        return match (true) {
            $monthsAgo <= 11 => 12,
            $monthsAgo <= 23 => 24,
            $monthsAgo <= 35 => 36,
            $monthsAgo <= 59 => 60,
            default => 0,
        };
    }

    private function rangeLabel(int $months): string
    {
        return match ($months) {
            3 => '3M',
            6 => '6M',
            12 => '1Y',
            24 => '2Y',
            36 => '3Y',
            60 => '5Y',
            0 => 'All',
            default => throw new \InvalidArgumentException(sprintf('Unexpected range: %d', $months)),
        };
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
