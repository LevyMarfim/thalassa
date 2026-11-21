<?php
// src/Chart/TransactionChartBuilder.php

declare(strict_types=1);

namespace App\Chart;

use App\Enum\CurrencyEnum;
use App\Enum\OperationEnum;

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
     *     net: list<float> credits minus debits, for the cash-flow chart,
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
            $net = [];
            foreach ($monthKeys as $key) {
                $row = $values[$key] ?? null;
                $monthCredits = round((float) ($row['credits'] ?? 0), 2);
                $monthDebits = round((float) ($row['debits'] ?? 0), 2);
                $credits[] = $monthCredits;
                $debits[] = $monthDebits;
                // Debits are stored negative, and the repository already
                // normalises them to magnitudes, so this is the signed cash
                // flow for the month rather than a second source of truth.
                $net[] = round($monthCredits - $monthDebits, 2);
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
                'net' => $net,
            ];
        }

        return $charts;
    }

    /**
     * Absolute money moved per operation, one doughnut per currency.
     *
     * @param list<array{operation: string, currency: string, total: float}> $rows
     *
     * @return list<array{currency: string, title: string, symbol: string, labels: list<string>, values: list<float>, colors: list<string>, period: string}>
     */
    public function buildOperationValueMix(array $rows, bool $filtered = false): array
    {
        return $this->valueMixByCurrency(
            $rows,
            'operation',
            static fn (string $value): string => OperationEnum::from($value)->labels(),
            static fn (string $value, int $index): string => match ($value) {
                OperationEnum::CREDIT->value => '#10b981',
                OperationEnum::DEBIT->value => '#ef4444',
                default => '#94a3b8',
            },
            $filtered,
        );
    }

    /**
     * Absolute money moved per operation type, one doughnut per currency.
     *
     * @param list<array{type: string, currency: string, total: float}> $rows
     *
     * @return list<array{currency: string, title: string, symbol: string, labels: list<string>, values: list<float>, colors: list<string>, period: string}>
     */
    public function buildTypeValueMix(array $rows, bool $filtered = false): array
    {
        return $this->valueMixByCurrency(
            $rows,
            'type',
            static fn (string $value): string => $value,
            static fn (string $value, int $index): string => 'Other' === $value
                ? '#94a3b8'
                : self::PALETTE[$index % \count(self::PALETTE)],
            $filtered,
        );
    }

    /**
     * Full-history monthly totals per operation type, one panel per currency.
     * The browser switches series with the type picker; no server round-trip.
     *
     * @param list<string>                                                             $monthKeys
     * @param list<array{month: string, type: string, currency: string, total: float}> $rows
     *
     * @return list<array{
     *     currency: string,
     *     title: string,
     *     symbol: string,
     *     labels: list<string>,
     *     types: list<array{type: string, values: list<float>, total: float}>,
     *     period: string,
     * }>
     */
    public function buildMonthlyType(array $monthKeys, array $rows): array
    {
        if ([] === $monthKeys) {
            return [];
        }

        $labels = array_map($this->formatMonthLabel(...), $monthKeys);
        $period = $labels[0] === $labels[array_key_last($labels)]
            ? $labels[0]
            : $labels[0].' – '.$labels[array_key_last($labels)];
        $monthIndex = array_flip($monthKeys);
        $grouped = [];
        $totals = [];
        foreach ($rows as $row) {
            $currency = self::scalar($row['currency']);
            $type = (string) $row['type'];
            $grouped[$currency][$type][$row['month']] = ($grouped[$currency][$type][$row['month']] ?? 0) + (float) $row['total'];
            $totals[$currency][$type] = ($totals[$currency][$type] ?? 0) + (float) $row['total'];
        }

        $charts = [];
        foreach (CurrencyEnum::cases() as $currency) {
            $currencyTotals = $totals[$currency->value] ?? [];
            if ([] === $currencyTotals) {
                continue;
            }

            arsort($currencyTotals);
            $types = array_keys($currencyTotals);
            $visible = \array_slice($types, 0, 8);
            $rest = \array_slice($types, 8);

            $series = [];
            foreach ($visible as $type) {
                $series[] = $this->monthlySeries($type, $grouped[$currency->value][$type], $monthIndex, $monthKeys);
            }
            if ([] !== $rest) {
                $other = array_fill(0, \count($monthKeys), 0.0);
                $otherTotal = 0.0;
                foreach ($rest as $type) {
                    $otherTotal += $currencyTotals[$type];
                    foreach ($grouped[$currency->value][$type] as $month => $value) {
                        if (isset($monthIndex[$month])) {
                            $other[$monthIndex[$month]] += $value;
                        }
                    }
                }
                $series[] = [
                    'type' => 'Other',
                    'values' => array_map(static fn (float $value): float => round($value, 2), $other),
                    'total' => round($otherTotal, 2),
                ];
            }

            $charts[] = [
                'currency' => $currency->value,
                'title' => $currency->labels(),
                'symbol' => $currency->symbol(),
                'labels' => $labels,
                'types' => $series,
                'period' => $period,
            ];
        }

        return $charts;
    }

    /**
     * @param array<string, float> $monthlyValues
     * @param array<string, int>   $monthIndex
     * @param list<string>         $monthKeys
     *
     * @return array{type: string, values: list<float>, total: float}
     */
    private function monthlySeries(string $type, array $monthlyValues, array $monthIndex, array $monthKeys): array
    {
        $values = array_fill(0, \count($monthKeys), 0.0);
        foreach ($monthlyValues as $month => $value) {
            if (isset($monthIndex[$month])) {
                $values[$monthIndex[$month]] = $value;
            }
        }

        return [
            'type' => $type,
            'values' => array_map(static fn (float $value): float => round($value, 2), $values),
            'total' => round(array_sum($values), 2),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param callable(string): string   $label
     * @param callable(string, int): string $color
     *
     * @return list<array<string, mixed>>
     */
    private function valueMixByCurrency(
        array $rows,
        string $groupKey,
        callable $label,
        callable $color,
        bool $filtered,
    ): array {
        $grouped = [];
        foreach ($rows as $row) {
            $currency = self::scalar($row['currency']);
            $group = self::scalar($row[$groupKey]);
            $grouped[$currency][$group] = ($grouped[$currency][$group] ?? 0) + (float) $row['total'];
        }

        $charts = [];
        foreach (CurrencyEnum::cases() as $currency) {
            $groups = $grouped[$currency->value] ?? [];
            if ([] === $groups) {
                continue;
            }

            arsort($groups);
            $visible = \array_slice($groups, 0, 6, true);
            $rest = \array_slice($groups, 6, null, true);
            if ([] !== $rest) {
                $visible['Other'] = array_sum($rest);
            }

            $labels = [];
            $values = [];
            $colors = [];
            foreach ($visible as $group => $value) {
                $labels[] = $label((string) $group);
                $values[] = round($value, 2);
                $colors[] = $color((string) $group, \count($colors));
            }

            $charts[] = [
                'currency' => $currency->value,
                'title' => $currency->labels(),
                'symbol' => $currency->symbol(),
                'labels' => $labels,
                'values' => $values,
                'colors' => $colors,
                'period' => $filtered ? 'Filtered' : 'All time',
            ];
        }

        return $charts;
    }

    /** Doctrine may hydrate an enum column as its case or its backing value. */
    private static function scalar(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
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
