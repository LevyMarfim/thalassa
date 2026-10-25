<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\TransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(
        AccountRepository $accountRepository,
        TransactionRepository $transactionRepository,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('home/index.html.twig', [
            'accounts' => $accountRepository->findByUser($user),
            'balances' => $accountRepository->findBalancesByUser($user),
            // findByUser orders by movement DESC, so the head of the list is
            // the most recent activity. Five is enough for a dashboard glance;
            // the full list lives on the transactions page.
            'recentTransactions' => $transactionRepository->findByUser($user, null, 5),
        ]);
    }
}
