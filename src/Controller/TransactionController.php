<?php
// src/Controller/TransactionController.php

namespace App\Controller;

use App\Chart\TransactionChartBuilder;
use App\Dto\TransactionFilter;
use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CurrencyEnum;
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
        TransactionChartBuilder $chartBuilder,
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

        // Carried into edit/delete links so those round-trips can return to
        // exactly this filtered view instead of the full ledger.
        $listQuery = $this->listQuery($request);

        $transactions = $transactionRepository->findByUser($user, $filter);

        // The summary strip follows the same filter as the table and charts.
        $currencySummaries = [];
        foreach ($transactionRepository->findTotalsByUser($user, $filter) as $total) {
            $currency = CurrencyEnum::from($total['currency']);
            $currencySummaries[] = [
                'currency' => $currency,
                'symbol' => $currency->symbol(),
                'total' => $total['total'],
                'count' => $total['count'],
            ];
        }

        // Charts follow the same filter as the table and summary strip. The
        // monthly chart receives the full filtered history and lets the browser
        // pick the visible range; the type chart keeps at most seven slices.
        $monthlyActivity = $transactionRepository->findMonthlyActivityByUser($user, $filter);
        $monthlyCharts = $chartBuilder->buildMonthly(
            $monthlyActivity['months'],
            $monthlyActivity['rows'],
        );
        $typeChart = $chartBuilder->buildTypeMix(
            $transactionRepository->findOperationTypeCountsByUser($user, $filter),
            $filter->isActive(),
        );

        return $this->render('transactions/index.html.twig', [
            'filterForm' => $filterForm,
            'filter' => $filter,
            'listQuery' => $listQuery,
            'transactions' => $transactions,
            'filteredAccount' => $filter->getAccount(),
            'transactionCount' => $transactionRepository->countByUser($user, $filter),
            'currencySummaries' => $currencySummaries,
            'monthlyCharts' => $monthlyCharts,
            'typeChart' => $typeChart,
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
            // Carries the incoming account filter through the "Back" link so
            // leaving the form returns to the same filtered list.
            'filteredAccount' => $account,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_transactions_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Transaction $transaction,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessOwned($transaction);

        // The edit link, form action and final redirect all carry the same
        // filter query, so saving (or cancelling) returns to the user's view.
        $listQuery = $this->listQuery($request);

        $form = $this->createForm(TransactionFormType::class, $transaction);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Transaction updated.');

            return $this->redirectToRoute('app_transactions', $listQuery);
        }

        return $this->render('transactions/edit.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
            'listQuery' => $listQuery,
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

        $listQuery = $this->listQuery($request);

        $entityManager->remove($transaction);
        $entityManager->flush();
        $this->addFlash('success', 'Transaction removed.');

        return $this->redirectToRoute('app_transactions', $listQuery);
    }

    /**
     * The filter parameters worth carrying through an edit/delete round-trip.
     * Both the legacy ?account= link and the filter form's nested
     * transaction_filter[...] values are preserved; unrelated query keys are
     * dropped so they cannot leak into the redirected listing.
     *
     * @return array<string, mixed>
     */
    private function listQuery(Request $request): array
    {
        $query = $request->query->all();
        $params = [];

        if (isset($query['account']) && \is_scalar($query['account'])) {
            $params['account'] = $query['account'];
        }

        if (isset($query['transaction_filter']) && \is_array($query['transaction_filter'])) {
            $params['transaction_filter'] = $query['transaction_filter'];
        }

        return $params;
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
