<?php
// src/Chart/MonthlyBalanceChartBuilder.php

declare(strict_types=1);

namespace App\Chart;

use App\Entity\Account;
use App\Enum\CurrencyEnum;

/**
 * Turns the repository's month-end balances into the plain arrays Chart.js
 * consumes.
 *
 * No geometry lives here: Chart.js owns scaling, axes and drawing. The
 * builder only groups accounts by currency (a BRL line and a USD line must
 * never share an axis) and passes through each account's saved chart colour.
 * The result is JSON-encoded into a data attribute and read by the
 * balance-chart Stimulus controller.
 */
final class MonthlyBalanceChartBuilder
{
    /**
     * Same pt_BR abbreviations as App\Twig\DateOnlyExtension. Chart labels are
     * never parsed back, so the dotless spelling is only for consistency.
     *
     * @var list<string>
     */
    private const array MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /**
     * @param list<Account>           $accounts ordered as they should appear in the legends
     * @param array<int, list<float>> $balances account id => one value per month
     * @param list<string>            $months   Y-m keys in chronological order
     *
     * @return list<array{
     *     currency: string,
     *     title: string,
     *     symbol: string,
     *     labels: list<string>,
     *     datasets: list<array{accountId: int, label: string, data: list<float>, color: string}>,
     * }> one chart per currency that has activity
     */
    public function build(array $accounts, array $balances, array $months): array
    {
        if ([] === $months) {
            return [];
        }

        $labels = array_map($this->formatMonthLabel(...), $months);
        $charts = [];

        // Iterating the enum (rather than the accounts) gives a stable panel
        // order — BRL before USD — regardless of alphabetical account names.
        foreach (CurrencyEnum::cases() as $currency) {
            $datasets = [];

            foreach ($accounts as $account) {
                if ($account->getCurrency() !== $currency) {
                    continue;
                }

                $id = $account->getId();
                if (null === $id || !isset($balances[$id])) {
                    continue;
                }

                $datasets[] = [
                    'accountId' => $id,
                    'label' => (string) $account->getName(),
                    'data' => array_map(
                        static fn (float $value): float => round($value, 2),
                        $balances[$id],
                    ),
                    'color' => $account->getColor() ?? Account::DEFAULT_COLOR,
                ];
            }

            if ([] === $datasets) {
                continue;
            }

            $charts[] = [
                'currency' => $currency->value,
                'title' => $currency->labels(),
                'symbol' => $currency->symbol(),
                'labels' => $labels,
                'datasets' => $datasets,
            ];
        }

        return $charts;
    }

    private function formatMonthLabel(string $monthKey): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $monthKey);
        if (false === $date) {
            return $monthKey;
        }

        return self::MONTHS[(int) $date->format('n') - 1].'/'.$date->format('y');
    }
}
