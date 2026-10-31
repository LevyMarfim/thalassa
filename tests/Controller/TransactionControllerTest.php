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
        self::assertCount(1, $crawler->filter('form[name="transaction_form"]'));
        self::assertSelectorTextContains('.tx-table', $transaction->getDescription());
        self::assertSelectorTextContains('.tx-table', '1.234,56');
    }

    /**
     * movement/liquidation are DATE columns: the table must print the calendar
     * day the database holds. It previously passed 'America/Sao_Paulo' to the
     * date filter, which re-interpreted the hydrated UTC midnight as 21:00 the
     * previous day — the listing showed every date one day early, while the
     * edit form (no conversion) and the database were both correct.
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
        self::assertStringContainsString('15/10/2026', $html);
        self::assertStringContainsString('17/10/2026', $html);
        self::assertStringNotContainsString('14/10/2026', $html);
        self::assertStringNotContainsString('16/10/2026', $html);
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

        $crawler = $client->request('GET', '/transactions');
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
        self::assertStringContainsString('20/10/2026', $row->html());
        self::assertStringContainsString('22/10/2026', $row->html());
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
    }

    public function testFilteredViewPreselectsTheAccountInTheForm(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $accountA = $data['account'];

        $crawler = $client->request('GET', sprintf('/transactions?account=%d', $accountA->getId()));

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="transaction_form"]')->form();
        self::assertSame((string) $accountA->getId(), $form->get('transaction_form[account]')->getValue());
    }

    public function testCreatingFromFilteredViewKeepsTheFilter(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $data = $this->seedTransactionData();
        $accountA = $data['account'];
        $description = $this->uniqueDescription('FILTRO-CRIADO');

        $crawler = $client->request('GET', sprintf('/transactions?account=%d', $accountA->getId()));
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

        $crawler = $client->request('GET', '/transactions');
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

        $crawler = $client->request('GET', '/transactions');
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

        $crawler = $client->request('GET', '/transactions');
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

        $crawler = $client->request('GET', '/transactions');
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

        $crawler = $client->request('GET', '/transactions');
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

        $crawler = $client->request('GET', '/transactions');

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

        $crawler = $client->request('GET', '/transactions');

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

        $crawler = $client->request('GET', '/transactions');
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
        self::assertSame(
            $transaction->getDescription(),
            $crawler->filter('input[name="transaction_form[description]"]')->attr('value')
        );
        // The server renders its numeric wire value (the picker re-displays
        // it as 15/out/2026 client-side; the fixture uses 2026-10-15/17).
        self::assertSame('15/10/2026', $crawler->filter('input[name="transaction_form[movement]"]')->attr('value'));
        self::assertSame('17/10/2026', $crawler->filter('input[name="transaction_form[liquidation]"]')->attr('value'));
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
    private function createAccountWithTransaction(string $description, float $totalPrice): Account
    {
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
        $account->setCurrency(CurrencyEnum::BRL);
        $em->persist($account);

        $transaction = new Transaction();
        $transaction->setUser($owner);
        $transaction->setAccount($account);
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

        return $account;
    }

    private function createTransaction(string $description, float $totalPrice, ?User $owner = null): Transaction
    {
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
