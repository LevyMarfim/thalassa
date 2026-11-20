<?php
// src/Chart/TransactionChartBuilder.php

declare(strict_types=1);

namespace App\Chart;

use App\Enum\CurrencyEnum;

/**
 * Shapes the transactions aggregates into the small JSON payloads consumed by
 * assets/controllers/transaction_chart_controller.js.
 *
 * Deliberately dumb: no geometry, no crosshair. Chart.js handles scales and
 * tooltips; the controller owns the monthly chart's range buttons. This class
 * only fills gaps in months, picks a sensible starting range per currency and
 * caps the type list.
 */
final class TransactionChartBuilder
{
    /** @var list<string> */
    private const array MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /** @var list<string> */
    private const array PALETTE = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4'];

    /**
     * @param list<string>                                                             $monthKeys full filtered history as Y-m months, chronological
     * @param list<array{month: string, currency: string, credits: float, debits: float}> $rows
     *
     * @return list<array{
     *     currency: string,
     *     title: string,
     *     symbol: string,
     *     period: string,
     *     defaultRange: int, months in the starting range, 0 for All,
     *     labels: list<string>,
     *     credits: list<float>,
     *     debits: list<float>,
     * }> one compact chart per currency with activity somewhere in the history
     */
    public function buildMonthly(array $monthKeys, array $rows): array
    {
        if ([] === $monthKeys) {
            return [];
        }

        $labels = array_map($this->formatMonthLabel(...), $monthKeys);
        $period = $labels[0] === $labels[array_key_last($labels)]
            ? $labels[0]
            : $labels[0].' – '.$labels[array_key_last($labels)];
        $lastIndex = array_key_last($monthKeys);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['currency']][$row['month']] = $row;
        }

        $charts = [];
        foreach (CurrencyEnum::cases() as $currency) {
            $values = $grouped[$currency->value] ?? null;
            if (null === $values) {
                continue;
            }

            $credits = [];
            $debits = [];
            foreach ($monthKeys as $key) {
                $row = $values[$key] ?? null;
                $credits[] = round((float) ($row['credits'] ?? 0), 2);
                $debits[] = round((float) ($row['debits'] ?? 0), 2);
            }

            // Open on the shortest preset that still contains this currency's
            // most recent movement. A ledger last touched in jun/22 therefore
            // starts at 5Y, one touched two years ago at 2Y, and only genuinely
            // old ledgers fall back to All.
            $activeIndexes = [];
            foreach (array_keys($values) as $month) {
                $index = array_search($month, $monthKeys, true);
                if (false !== $index) {
                    $activeIndexes[] = $index;
                }
            }
            $monthsAgo = [] === $activeIndexes ? 0 : $lastIndex - max($activeIndexes);
            $defaultRange = match (true) {
                \count($monthKeys) < 12 => 0,
                $monthsAgo <= 11 => 12,
                $monthsAgo <= 23 => 24,
                $monthsAgo <= 35 => 36,
                $monthsAgo <= 59 => 60,
                default => 0,
            };

            $charts[] = [
                'currency' => $currency->value,
                'title' => $currency->labels(),
                'symbol' => $currency->symbol(),
                'period' => $period,
                'defaultRange' => $defaultRange,
                'labels' => $labels,
                'credits' => $credits,
                'debits' => $debits,
            ];
        }

        return $charts;
    }

    /**
     * Keeps the first six types and folds the tail into "Other" so the legend
     * stays readable in a narrow card.
     *
     * @param list<array{type: string, count: int}> $rows
     *
     * @return array{
     *     labels: list<string>,
     *     counts: list<int>,
     *     colors: list<string>,
     *     period: string,
     * }|null
     */
    public function buildTypeMix(array $rows, bool $filtered = false): ?array
    {
        if ([] === $rows) {
            return null;
        }

        $visible = \array_slice($rows, 0, 6);
        $rest = \array_slice($rows, 6);
        if ([] !== $rest) {
            $visible[] = [
                'type' => 'Other',
                'count' => array_sum(array_column($rest, 'count')),
            ];
        }

        return [
            'labels' => array_column($visible, 'type'),
            'counts' => array_map('intval', array_column($visible, 'count')),
            'period' => $filtered ? 'Filtered' : 'All time',
            'colors' => array_map(
                static fn (int $index): string => self::PALETTE[$index % \count(self::PALETTE)],
                array_keys($visible),
            ),
        ];
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
