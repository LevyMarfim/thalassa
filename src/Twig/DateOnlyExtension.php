<?php
// src/Twig/DateOnlyExtension.php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Formats date-only values — Doctrine DATE columns — as the calendar date the
 * database holds.
 *
 * A DATE column is a calendar date, not an instant: Doctrine hydrates it as
 * midnight in PHP's default timezone (UTC here). Handing that DateTime to
 * Twig's `date` filter together with a display timezone re-interprets the
 * instant, so `|date('d/m/Y', 'America/Sao_Paulo')` turned 2026-10-22 into
 * 21/10/2026 — one day early on the transactions table.
 *
 * `date_only` formats in the value's own timezone, which makes that shift
 * structurally impossible: the filter accepts no timezone to convert to.
 * Real instants (createdAt, lastAccessAt) keep using `|date(...)` with an
 * explicit display timezone.
 */
final class DateOnlyExtension extends AbstractExtension
{
    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('date_only', $this->formatDateOnly(...)),
        ];
    }

    public function formatDateOnly(?\DateTimeInterface $date, string $format = 'd/m/Y'): string
    {
        return $date?->format($format) ?? '';
    }
}
