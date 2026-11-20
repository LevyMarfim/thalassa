<?php

namespace App\Controller;

use App\Chart\MonthlyBalanceChartBuilder;
use App\Chart\TransactionChartBuilder;
use App\Entity\User;
use App\Enum\CurrencyEnum;
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
        MonthlyBalanceChartBuilder $balanceChartBuilder,
        TransactionChartBuilder $transactionChartBuilder,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $accounts = $accountRepository->findByUser($user);
        // Null means all time: the charts carry the whole history and the
        // client-side range buttons slice it down to the chosen window.
        $monthlyBalances = $accountRepository->findMonthlyBalancesByUser($user, null);
        $monthlyActivity = $transactionRepository->findMonthlyActivityByUser($user);
        $monthlyTypeActivity = $transactionRepository->findMonthlyTypeActivityByUser($user);

        // The strip describes the whole ledger, which is exactly what an
        // unfiltered dashboard should show.
        $currencySummaries = [];
        foreach ($transactionRepository->findTotalsByUser($user) as $total) {
            $currency = CurrencyEnum::from($total['currency']);
            $currencySummaries[] = [
                'currency' => $currency,
                'symbol' => $currency->symbol(),
                'total' => $total['total'],
                'count' => $total['count'],
            ];
        }

        return $this->render('home/index.html.twig', [
            // One panel per currency: a BRL line and a USD line on the same
            // axis would look comparable when they are not.
            'charts' => $balanceChartBuilder->build(
                $accounts,
                $monthlyBalances['balances'],
                $monthlyBalances['months'],
            ),
            'monthlyCharts' => $transactionChartBuilder->buildMonthly(
                $monthlyActivity['months'],
                $monthlyActivity['rows'],
            ),
            'monthlyTypeCharts' => $transactionChartBuilder->buildMonthlyType(
                $monthlyTypeActivity['months'],
                $monthlyTypeActivity['rows'],
            ),
            // Values are absolute money moved, split per currency: adding BRL
            // and USD into one slice would imply an exchange rate we don't have.
            'operationCharts' => $transactionChartBuilder->buildOperationValueMix(
                $transactionRepository->findOperationTotalsByUser($user),
            ),
            'typeCharts' => $transactionChartBuilder->buildTypeValueMix(
                $transactionRepository->findOperationTypeTotalsByUser($user),
            ),
            'currencySummaries' => $currencySummaries,
            'transactionCount' => $transactionRepository->countByUser($user),
            // findByUser orders by movement DESC, so the head of the list is
            // the most recent activity. Five is enough for a dashboard glance;
            // the full list lives on the transactions page.
            'recentTransactions' => $transactionRepository->findByUser($user, null, 5),
        ]);
    }
}
