<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\OperationType;
use App\Entity\Ticker;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CurrencyEnum;
use App\Enum\OperationEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Spanning the whole window is the layout's default (the main_class block
 * renders dash-main--wide unless a template overrides it), so this walks every
 * dashboard route to prove no page silently falls back to the 960px reading
 * cap. Width is only observable in the HTML through that class, which makes it
 * exactly the thing a template can drop without anything looking obviously
 * broken in review.
 */
final class DashboardLayoutTest extends WebTestCase
{
    public function testEveryDashboardPageSpansFullWidth(): void
    {
        $client = static::createClient();
        $user = $this->createUser();
        $client->loginUser($user);

        [$account, $operationType, $ticker, $transaction] = $this->createFixtures($user);

        $routes = [
            '/' => 'home dashboard',
            '/accounts' => 'accounts list',
            \sprintf('/accounts/%d/edit', $account->getId() ?? 0) => 'account edit',
            '/settings' => 'settings hub',
            '/settings/account' => 'settings account',
            '/settings/operation-types' => 'operation types list',
            \sprintf('/settings/operation-types/%d/edit', $operationType->getId() ?? 0) => 'operation type edit',
            '/settings/tickers' => 'tickers list',
            \sprintf('/settings/tickers/%d/edit', $ticker->getId() ?? 0) => 'ticker edit',
            '/transactions' => 'transactions list',
            \sprintf('/transactions/%d/edit', $transaction->getId() ?? 0) => 'transaction edit',
        ];

        foreach ($routes as $route => $label) {
            $crawler = $client->request('GET', $route);

            self::assertResponseIsSuccessful(\sprintf('%s did not return 200', $label));
            self::assertCount(
                1,
                $crawler->filter('main.dash-main.dash-main--wide'),
                \sprintf('%s is not spanning the full window', $label)
            );
        }
    }

    /**
     * The sidebar is a 76px icon rail that expands on hover (or keyboard
     * focus), entirely in CSS — no toggle button, no controller, no stored
     * state. The markup contract is therefore narrow but critical: the labels
     * must be in the DOM for the stylesheet to reveal, and every link needs
     * its own accessible name because its visible label is hidden in the rail
     * state.
     */
    public function testSidebarIsACssOnlyIconRailWithAccessibleLinks(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        // Nothing JS-driven toggles the sidebar: the stylesheet does, so a
        // collapsed rail cannot flash or lose its state across navigations.
        self::assertCount(0, $crawler->filter('.dash[data-controller]'));
        self::assertCount(0, $crawler->filter('.dash-sidebar button'));
        self::assertStringNotContainsString('thalassa:sidebar', $crawler->filter('head')->html());

        $sidebar = $crawler->filter('aside.dash-sidebar');
        self::assertCount(1, $sidebar);
        self::assertCount(1, $sidebar->filter('.dash-brand__name'));

        // The labels are hidden by CSS in the rail state, so they must exist in
        // the markup or hovering would reveal nothing.
        self::assertSame(
            ['Dashboard', 'Accounts', 'Transactions', 'Settings'],
            $sidebar->filter('.dash-nav__label')->each(static fn (Crawler $label): string => $label->text())
        );
        self::assertCount(1, $sidebar->filter('.dash-user__logout-label'));

        $links = $sidebar->filter('.dash-nav a');
        self::assertCount(4, $links);
        foreach ($links as $link) {
            \assert($link instanceof \DOMElement);
            self::assertNotSame('', $link->getAttribute('aria-label'));
            self::assertSame($link->getAttribute('aria-label'), $link->getAttribute('title'));
            // One icon per link, so the rail is still navigable by sight.
            self::assertCount(1, $link->getElementsByTagName('svg'));
        }
    }

    /**
     * One of each entity the edit routes need — an id that does not resolve is
     * a 404, which would fail the width assertion for the wrong reason.
     *
     * No clear() between flushes: clearing detaches everything fetched before
     * it, and a detached association looks like an unmanaged new entity to
     * persist(). Keeping the unit of work open avoids that entirely.
     */
    private function createFixtures(User $owner): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        $user = $em->getRepository(User::class)->find($owner->getId());
        \assert($user instanceof User);

        $suffix = \strtoupper(\substr(\uniqid(), -6));

        $account = new Account();
        $account->setUser($user);
        $account->setName('ACC'.$suffix);
        $account->setCurrency(CurrencyEnum::BRL);

        $operationType = new OperationType();
        $operationType->setType('LAY'.$suffix);

        $ticker = new Ticker();
        $ticker->setTicker('T'.$suffix);
        // name is NOT NULL.
        $ticker->setName('Name T'.$suffix);

        // Dependencies first, so the transaction's FK targets rows that already
        // have ids rather than relying on insert-order sorting.
        $em->persist($account);
        $em->persist($operationType);
        $em->persist($ticker);
        $em->flush();

        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setAccount($account);
        $transaction->setOperationType($operationType);
        $transaction->setTicker($ticker);
        $transaction->setOperation(OperationEnum::CREDIT);
        $transaction->setMovement(new \DateTime('2026-10-15'));
        $transaction->setLiquidation(new \DateTime('2026-10-17'));
        $transaction->setDescription('Layout '.$suffix);
        $transaction->setTotalPrice(10.00);

        $em->persist($transaction);
        $em->flush();

        return [$account, $operationType, $ticker, $transaction];
    }

    private function createUser(): User
    {
        $container = static::getContainer();
        $username = \sprintf('layout-%s', \uniqid());

        $user = new User();
        $user->setEmail($username.'@example.com');
        $user->setUsername($username);
        $user->setPassword(
            $container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password-123')
        );

        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $em->clear();

        return $user;
    }
}
