<?php
// src/Controller/TransactionController.php

namespace App\Controller;

use App\Entity\Transaction;
use App\Entity\User;
use App\Form\TransactionFormType;
use App\Repository\OperationTypeRepository;
use App\Repository\TickerRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/transactions')]
final class TransactionController extends AbstractController
{
    #[Route('', name: 'app_transactions', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        TransactionRepository $transactionRepository,
        OperationTypeRepository $operationTypeRepository,
        TickerRepository $tickerRepository,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $transaction = new Transaction();
        $transaction->setUser($user);

        $form = $this->createForm(TransactionFormType::class, $transaction);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($transaction);
            $entityManager->flush();
            $this->addFlash('success', 'Transaction added.');

            return $this->redirectToRoute('app_transactions');
        }

        $transactions = $transactionRepository->findByUser($user);

        return $this->render('transactions/index.html.twig', [
            'form' => $form,
            'transactions' => $transactions,
            'operationTypes' => $operationTypeRepository->findBy([], ['type' => 'ASC']),
            'tickers' => $tickerRepository->findBy([], ['ticker' => 'ASC']),
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
