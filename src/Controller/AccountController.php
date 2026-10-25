<?php
// src/Controller/AccountController.php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\User;
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
        $balances = $accountRepository->findBalancesByUser($user);

        return $this->render('accounts/index.html.twig', [
            'form' => $form,
            'accounts' => $accountRepository->findByUser($user),
            'balances' => $balances,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_accounts_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Account $account,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessOwned($account);

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
