<?php
// src/Controller/TransactionController.php

namespace App\Controller;

use App\Dto\TransactionFilter;
use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\User;
use App\Form\TransactionFilterType;
use App\Form\TransactionFormType;
use App\Repository\AccountRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/transactions')]
final class TransactionController extends AbstractController
{
    #[Route('', name: 'app_transactions', methods: ['GET'])]
    public function index(
        Request $request,
        AccountRepository $accountRepository,
        TransactionRepository $transactionRepository,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $filter = new TransactionFilter();

        // Backwards-compatible ?account= link from the accounts list. It seeds
        // the filter form; submitting the form itself produces
        // transaction_filter[account]=… instead.
        if ($request->query->has('account')) {
            $filter->setAccount($this->resolveAccount($request, $accountRepository, $user));
        }

        $filterForm = $this->createForm(TransactionFilterType::class, $filter, ['user' => $user]);
        $filterForm->handleRequest($request);

        return $this->render('transactions/index.html.twig', [
            'filterForm' => $filterForm,
            'transactions' => $transactionRepository->findByUser($user, $filter),
            'filteredAccount' => $filter->getAccount(),
        ]);
    }

    #[Route('/new', name: 'app_transactions_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        // Arriving from a filtered list pre-selects that account; the query
        // parameter is also used below to return to the same filtered view.
        $account = null;
        if ($request->query->has('account')) {
            $account = $this->resolveAccount($request, $accountRepository, $user);
        }

        $transaction = new Transaction();
        $transaction->setUser($user);
        if (null !== $account) {
            $transaction->setAccount($account);
        }

        $form = $this->createForm(TransactionFormType::class, $transaction, [
            // A blank liquidation date falls back to the movement date.
            'fill_liquidation_from_movement' => true,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($transaction);
            $entityManager->flush();
            $this->addFlash('success', 'Transaction added.');

            // Stay on the filtered view rather than dumping the user back on
            // the full list they just navigated away from.
            return $this->redirectToRoute(
                'app_transactions',
                null === $account ? [] : ['account' => $account->getId()]
            );
        }

        return $this->render('transactions/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_transactions_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Transaction $transaction,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessOwned($transaction);

        $form = $this->createForm(TransactionFormType::class, $transaction);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Transaction updated.');

            return $this->redirectToRoute('app_transactions');
        }

        return $this->render('transactions/edit.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_transactions_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Transaction $transaction,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessOwned($transaction);

        if (!$this->isCsrfTokenValid('delete-transaction', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($transaction);
        $entityManager->flush();
        $this->addFlash('success', 'Transaction removed.');

        return $this->redirectToRoute('app_transactions');
    }

    /**
     * Resolves the optional ?account= quick filter. Anything that is not an
     * account of the current user is a 404 — never a 403, which would confirm
     * the id exists.
     */
    private function resolveAccount(Request $request, AccountRepository $accountRepository, User $user): Account
    {
        $raw = $request->query->get('account');
        $account = \is_string($raw) && ctype_digit($raw)
            ? $accountRepository->find((int) $raw)
            : null;

        if (!$account instanceof Account || $account->getUser()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Account not found.');
        }

        return $account;
    }

    /**
     * The {id} route param is an unauthenticated handle on someone else's row,
     * so ownership is checked explicitly rather than relying on the query scope.
     * 404 (not 403) so we do not confirm that the id exists at all.
     */
    private function denyAccessUnlessOwned(Transaction $transaction): void
    {
        if ($transaction->getUser()?->getId() !== $this->getUser()?->getId()) {
            throw $this->createNotFoundException('Transaction not found.');
        }
    }
}
