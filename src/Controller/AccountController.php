<?php
// src/Controller/AccountController.php

namespace App\Controller;

use App\Dto\AccountFilter;
use App\Entity\Account;
use App\Entity\User;
use App\Enum\CurrencyEnum;
use App\Form\AccountFilterType;
use App\Form\AccountFormType;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/accounts')]
final class AccountController extends AbstractController
{
    #[Route('', name: 'app_accounts', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        // A GET filter form: a filtered list is a URL that can be bookmarked
        // and shared (within the same account, of course).
        $filter = new AccountFilter();
        $filterForm = $this->createForm(AccountFilterType::class, $filter);
        $filterForm->handleRequest($request);

        $account = new Account();
        $account->setUser($user);

        $form = $this->createForm(AccountFormType::class, $account);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($account);
            $entityManager->flush();
            $this->addFlash('success', 'Account created.');

            return $this->redirectToRoute('app_accounts');
        }

        // One query for every balance, rather than one per account.
        $allAccounts = $accountRepository->findByUser($user);
        $balances = $accountRepository->findBalancesByUser($user);

        // The summary deliberately uses every account, not the filtered rows:
        // it is a picture of the whole ledger, not of the current search.
        $currencySummaries = [];
        foreach (CurrencyEnum::cases() as $currency) {
            $owned = array_filter(
                $allAccounts,
                static fn (Account $item): bool => $item->getCurrency() === $currency
            );
            if ([] === $owned) {
                continue;
            }

            $total = 0.0;
            foreach ($owned as $item) {
                $total += $balances[$item->getId()] ?? 0.0;
            }

            $currencySummaries[] = [
                'currency' => $currency,
                'symbol' => $currency->symbol(),
                'total' => round($total, 2),
                'count' => count($owned),
            ];
        }

        return $this->render('accounts/index.html.twig', [
            'form' => $form,
            'filterForm' => $filterForm,
            'filter' => $filter,
            'accounts' => $accountRepository->findByUser($user, $filter),
            'balances' => $balances,
            'currencySummaries' => $currencySummaries,
            'accountCount' => count($allAccounts),
            'activeCount' => count(array_filter(
                $allAccounts,
                static fn (Account $item): bool => $item->isActive()
            )),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_accounts_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Account $account,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
    ): Response {
        $this->denyAccessUnlessOwned($account);

        $user = $this->getUser();
        \assert($user instanceof User);
        $balance = $accountRepository->findBalancesByUser($user)[$account->getId()] ?? 0.0;

        $form = $this->createForm(AccountFormType::class, $account);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Account updated.');

            return $this->redirectToRoute('app_accounts');
        }

        return $this->render('accounts/edit.html.twig', [
            'account' => $account,
            'form' => $form,
            'balance' => $balance,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_accounts_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Account $account,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
    ): Response {
        $this->denyAccessUnlessOwned($account);

        if (!$this->isCsrfTokenValid('delete-account', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Deleting an account would orphan its transactions, which is why
        // account_id is NOT NULL. Deactivate instead of deleting history.
        if ($accountRepository->countTransactions($account) > 0) {
            $this->addFlash('error', sprintf(
                '“%s” still has transactions. Move or delete them first, or mark the account inactive instead.',
                $account->getName()
            ));

            return $this->redirectToRoute('app_accounts');
        }

        $entityManager->remove($account);
        $entityManager->flush();
        $this->addFlash('success', 'Account removed.');

        return $this->redirectToRoute('app_accounts');
    }

    /**
     * The {id} route param is an unauthenticated handle on someone else's row,
     * so ownership is checked explicitly rather than relying on the query scope.
     * 404 (not 403) so we do not confirm that the id exists at all.
     */
    private function denyAccessUnlessOwned(Account $account): void
    {
        if ($account->getUser()?->getId() !== $this->getUser()?->getId()) {
            throw $this->createNotFoundException('Account not found.');
        }
    }
}
