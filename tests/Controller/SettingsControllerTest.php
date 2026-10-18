<?php

namespace App\Tests\Controller;

use App\Entity\OperationType;
use App\Entity\Ticker;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SettingsControllerTest extends WebTestCase
{
    public function testAnonymousSettingsRedirectsToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/settings');
        self::assertResponseRedirects('/login');

        $client->request('GET', '/settings/account');
        self::assertResponseRedirects('/login');

        $client->request('GET', '/settings/operation-types');
        self::assertResponseRedirects('/login');

        $client->request('GET', '/settings/tickers');
        self::assertResponseRedirects('/login');
    }

    public function testSettingsHubLinksToEachSection(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        // The hub holds no forms of its own; it only links into the sections.
        self::assertCount(0, $crawler->filter('form'));
        self::assertSame(1, $crawler->filter('a[href="/settings/account"]')->count());
        self::assertSame(1, $crawler->filter('a[href="/settings/operation-types"]')->count());
        self::assertSame(1, $crawler->filter('a[href="/settings/tickers"]')->count());
    }

    public function testSettingsHubShowsSectionCounts(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $this->createOperationType($this->uniqueOperationType('PIX'));
        $this->createTicker('PETR');
        $this->createTicker('VALE');

        $crawler = $client->request('GET', '/settings');

        // Scoping to each card, because the count differs per section.
        self::assertStringContainsString('1 type', $crawler->filter('a[href="/settings/operation-types"]')->text());
        self::assertStringContainsString('2 tickers', $crawler->filter('a[href="/settings/tickers"]')->text());
    }

    public function testAccountPageShowsBothForms(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/settings/account');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[name="change_email_form"]'));
        self::assertCount(1, $crawler->filter('form[name="change_password_form"]'));
    }

    public function testAccountPageHighlightsAccountSection(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/settings/account');

        self::assertSelectorTextContains('.settings-nav__item--active', 'Account');
    }

    public function testChangeEmailSuccess(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);

        $newEmail = sprintf('new-%s@example.com', uniqid());
        $crawler = $client->request('GET', '/settings/account');
        $form = $crawler->filter('form[name="change_email_form"]')->form([
            'change_email_form[email]' => $newEmail,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings/account');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--success', 'email');

        $fresh = $this->findUser($user->getUsername());
        self::assertSame($newEmail, $fresh->getEmail());
    }

    public function testChangeEmailRejectsDuplicate(): void
    {
        $client = static::createClient();
        $other = $this->createUser();
        $user = $this->createUser();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings/account');
        $form = $crawler->filter('form[name="change_email_form"]')->form([
            'change_email_form[email]' => $other->getEmail(),
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertNotSame($other->getEmail(), $this->findUser($user->getUsername())->getEmail());
    }

    public function testChangePasswordSuccess(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings/account');
        $form = $crawler->filter('form[name="change_password_form"]')->form([
            'change_password_form[currentPassword]' => 'password-123',
            'change_password_form[newPassword][first]' => 'brand-new-password-1',
            'change_password_form[newPassword][second]' => 'brand-new-password-1',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings/account');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--success', 'password');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $fresh = $this->findUser($user->getUsername());
        self::assertTrue($hasher->isPasswordValid($fresh, 'brand-new-password-1'));
        self::assertFalse($hasher->isPasswordValid($fresh, 'password-123'));
    }

    public function testChangePasswordRejectsWrongCurrentPassword(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/settings/account');
        $form = $crawler->filter('form[name="change_password_form"]')->form([
            'change_password_form[currentPassword]' => 'wrong-password-1',
            'change_password_form[newPassword][first]' => 'brand-new-password-2',
            'change_password_form[newPassword][second]' => 'brand-new-password-2',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $fresh = $this->findUser($user->getUsername());
        self::assertTrue($hasher->isPasswordValid($fresh, 'password-123'));
    }

    public function testSettingsListsOperationTypes(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $pix = $this->uniqueOperationType('PIX');
        $boleto = $this->uniqueOperationType('Boleto');
        $this->createOperationType($pix);
        $this->createOperationType($boleto);

        $crawler = $client->request('GET', '/settings/operation-types');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.type-list', $pix);
        self::assertSelectorTextContains('.type-list', $boleto);
    }

    public function testOperationTypesPageHighlightsItsSection(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/settings/operation-types');

        self::assertSelectorTextContains('.settings-nav__item--active', 'Operation types');
    }

    public function testCreateOperationTypeSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $name = $this->uniqueOperationType('TED');

        $crawler = $client->request('GET', '/settings/operation-types');
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => $name,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings/operation-types');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.type-list', $name);
        self::assertNotNull($this->findOperationType($name));
    }

    public function testCreateOperationTypeRejectsBlank(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $before = $this->countOperationTypes();

        $crawler = $client->request('GET', '/settings/operation-types');
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => '',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countOperationTypes());
    }

    public function testCreateOperationTypeRejectsExactDuplicate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $name = $this->uniqueOperationType('Aporte');
        $this->createOperationType($name);
        $before = $this->countOperationTypes();

        $crawler = $client->request('GET', '/settings/operation-types');
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => $name,
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countOperationTypes());
    }

    public function testCreateOperationTypeRejectsCaseInsensitiveDuplicate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $name = $this->uniqueOperationType('Aporte');
        $this->createOperationType($name);
        $before = $this->countOperationTypes();

        $crawler = $client->request('GET', '/settings/operation-types');
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => strtoupper($name),
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countOperationTypes());
    }

    public function testCreateOperationTypeTrimsWhitespaceBeforeComparing(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $name = $this->uniqueOperationType('Aporte');
        $this->createOperationType($name);
        $before = $this->countOperationTypes();

        $crawler = $client->request('GET', '/settings/operation-types');
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => sprintf('   %s   ', $name),
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countOperationTypes());
    }

    public function testEditOperationTypeCannotTakeAnotherName(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $taken = $this->uniqueOperationType('Aporte');
        $this->createOperationType($taken);
        $target = $this->createOperationType($this->uniqueOperationType('Dividendo'));

        $crawler = $client->request('GET', sprintf('/settings/operation-types/%d/edit', $target->getId()));
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => strtolower($taken),
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        // The original name must be untouched.
        self::assertNotNull($this->findOperationType($target->getType()));
    }

    public function testEditOperationTypeCanKeepItsOwnName(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $name = $this->uniqueOperationType('Aporte');
        $type = $this->createOperationType($name);

        $crawler = $client->request('GET', sprintf('/settings/operation-types/%d/edit', $type->getId()));
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => $name,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings/operation-types');
        self::assertNotNull($this->findOperationType($name));
    }

    public function testOperationTypeIsNormalizedOnSave(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $name = $this->uniqueOperationType('Renda');

        $crawler = $client->request('GET', '/settings/operation-types');
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => sprintf('  %s   Fixa  ', $name),
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings/operation-types');
        // Collapsed to single spaces and trimmed, not stored verbatim.
        self::assertNotNull($this->findOperationType(sprintf('%s Fixa', $name)));
    }

    public function testDuplicateOperationTypeReachesDatabaseGuardAsLastResort(): void
    {
        // Bypasses the form entirely: even a direct INSERT of a case-variant
        // must be rejected by the unique index, not just the validator.
        $name = $this->uniqueOperationType('Aporte');
        $this->createOperationType($name);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->createOperationType(strtoupper($name));
    }

    public function testEditOperationTypePageRenders(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $name = $this->uniqueOperationType('PIX');
        $type = $this->createOperationType($name);

        $crawler = $client->request('GET', sprintf('/settings/operation-types/%d/edit', $type->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame($name, $crawler->filter('input[name="operation_type_form[type]"]')->attr('value'));
    }

    public function testEditOperationTypeSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $name = $this->uniqueOperationType('PIX');
        $newName = $this->uniqueOperationType('PIXInstant');
        $type = $this->createOperationType($name);

        $crawler = $client->request('GET', sprintf('/settings/operation-types/%d/edit', $type->getId()));
        $form = $crawler->filter('form[name="operation_type_form"]')->form([
            'operation_type_form[type]' => $newName,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings/operation-types');
        self::assertNotNull($this->findOperationType($newName));
        self::assertNull($this->findOperationType($name));
    }

    public function testEditOperationTypeNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/settings/operation-types/999999/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousEditRedirectsToLogin(): void
    {
        static::createClient()->request('GET', '/settings/operation-types/1/edit');

        self::assertResponseRedirects('/login');
    }

    // -------------------- Tickers --------------------

    public function testSettingsListsTickers(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $first = $this->createTicker('PETR');
        $second = $this->createTicker('AAPL');

        $crawler = $client->request('GET', '/settings/tickers');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[name="ticker_form"]'));
        self::assertSelectorTextContains('.ticker-list', $first->getTicker());
        self::assertSelectorTextContains('.ticker-list', $second->getTicker());
    }

    public function testTickersPageHighlightsItsSection(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/settings/tickers');

        self::assertSelectorTextContains('.settings-nav__item--active', 'Tickers');
    }

    public function testCreateTickerSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $symbol = $this->uniqueSymbol('PETR');

        $crawler = $client->request('GET', '/settings/tickers');
        $form = $crawler->filter('form[name="ticker_form"]')->form([
            'ticker_form[ticker]' => strtolower($symbol),
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings/tickers');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ticker-list', $symbol);

        // Normalized to uppercase so petr4 / PETR4 cannot both exist.
        self::assertNotNull($this->findTicker($symbol));
        self::assertNull($this->findTicker(strtolower($symbol)));
    }

    public function testCreateTickerRejectsDuplicate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $symbol = $this->createTicker('PETR')->getTicker();
        $before = $this->countTickers();

        $crawler = $client->request('GET', '/settings/tickers');
        $form = $crawler->filter('form[name="ticker_form"]')->form([
            'ticker_form[ticker]' => $symbol,
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countTickers());
    }

    public function testCreateTickerRejectsInvalidCharacters(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $before = $this->countTickers();

        $crawler = $client->request('GET', '/settings/tickers');
        $form = $crawler->filter('form[name="ticker_form"]')->form([
            'ticker_form[ticker]' => 'not a ticker!',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countTickers());
    }

    public function testCreateTickerRejectsBlank(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $before = $this->countTickers();

        $crawler = $client->request('GET', '/settings/tickers');
        $form = $crawler->filter('form[name="ticker_form"]')->form([
            'ticker_form[ticker]' => '',
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame($before, $this->countTickers());
    }

    public function testEditTickerPageRenders(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $ticker = $this->createTicker('PETR');

        $crawler = $client->request('GET', sprintf('/settings/tickers/%d/edit', $ticker->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame($ticker->getTicker(), $crawler->filter('input[name="ticker_form[ticker]"]')->attr('value'));
    }

    public function testEditTickerSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $ticker = $this->createTicker('PETR');
        $newSymbol = $this->uniqueSymbol('VALE');

        $crawler = $client->request('GET', sprintf('/settings/tickers/%d/edit', $ticker->getId()));
        $form = $crawler->filter('form[name="ticker_form"]')->form([
            'ticker_form[ticker]' => strtolower($newSymbol),
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings/tickers');
        self::assertNotNull($this->findTicker($newSymbol));
        self::assertNull($this->findTicker($ticker->getTicker()));
    }

    public function testEditTickerRejectsDuplicate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $taken = $this->createTicker('VALE')->getTicker();
        $ticker = $this->createTicker('PETR');

        $crawler = $client->request('GET', sprintf('/settings/tickers/%d/edit', $ticker->getId()));
        $form = $crawler->filter('form[name="ticker_form"]')->form([
            'ticker_form[ticker]' => $taken,
        ]);
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertNotNull($this->findTicker($ticker->getTicker()));
    }

    public function testEditTickerNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $client->request('GET', '/settings/tickers/999999/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteTickerSuccess(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $ticker = $this->createTicker('PETR');

        // Submit the delete form exactly as rendered (it carries the CSRF token).
        $crawler = $client->request('GET', '/settings/tickers');
        $form = $crawler->filter(sprintf('form[action="/settings/tickers/%d/delete"]', $ticker->getId()))->form();
        $client->submit($form);

        self::assertResponseRedirects('/settings/tickers');
        self::assertNull($this->findTicker($ticker->getTicker()));
    }

    public function testDeleteTickerRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $ticker = $this->createTicker('PETR');

        $client->request('POST', sprintf('/settings/tickers/%d/delete', $ticker->getId()), [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->findTicker($ticker->getTicker()));
    }

    public function testDeleteTickerRejectsGet(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());
        $ticker = $this->createTicker('PETR');

        $client->request('GET', sprintf('/settings/tickers/%d/delete', $ticker->getId()));

        self::assertResponseStatusCodeSame(405);
        self::assertNotNull($this->findTicker($ticker->getTicker()));
    }

    public function testAnonymousTickerRoutesRedirectToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/settings/tickers/1/edit');
        self::assertResponseRedirects('/login');

        $client->request('POST', '/settings/tickers/1/delete');
        self::assertResponseRedirects('/login');
    }

    /**
     * Ticker symbols carry a DB unique constraint and the test database is
     * shared across test methods, so every test must use its own symbol.
     */
    private function uniqueSymbol(string $prefix): string
    {
        return sprintf('%s%s', $prefix, strtoupper(substr(uniqid(), -5)));
    }

    private function createTicker(string $prefix): Ticker
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $ticker = new Ticker();
        $ticker->setTicker($this->uniqueSymbol($prefix));
        $entityManager->persist($ticker);
        $entityManager->flush();
        $entityManager->clear();

        return $ticker;
    }

    private function findTicker(string $symbol): ?Ticker
    {
        // Clear first so a rejected submit cannot be masked by the identity map.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager
            ->getRepository(Ticker::class)
            ->findOneBy(['ticker' => $symbol]);
    }

    private function countTickers(): int
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Ticker::class)
            ->count([]);
    }

    /**
     * operation_type is unique case-insensitively, and the test database is
     * shared across methods, so every test needs its own name.
     */
    private function uniqueOperationType(string $prefix): string
    {
        return sprintf('%s %s', $prefix, strtoupper(substr(uniqid(), -5)));
    }

    private function createOperationType(string $type): OperationType
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $operationType = new OperationType();
        $operationType->setType($type);
        $entityManager->persist($operationType);
        $entityManager->flush();
        $entityManager->clear();

        return $operationType;
    }

    private function findOperationType(string $type): ?OperationType
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(OperationType::class)
            ->findOneBy(['type' => $type]);
    }

    private function countOperationTypes(): int
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(OperationType::class)
            ->count([]);
    }

    private function createUser(?string $username = null): User
    {
        $username ??= sprintf('settings-%s', uniqid());
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

    private function findUser(string $username): User
    {
        // Clear first: a rejected submit still mutates the managed entity
        // in memory, so re-fetching must hit the database, not the identity map.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $user = $entityManager
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]);

        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
