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
 *
 * `date_only_short` is the dd/mmm/yyyy presentation (07/set/2023) used in
 * listings. The month names come from the table below rather than ICU: under
 * pt_BR, ICU abbreviates September as "set." — with a dot — and the field
 * parser (flatpickr) matches names exactly, so a dotted name cannot be read
 * back. Keeping the list here also pins the lowercase spelling the design
 * calls for. Keep it in sync with MONTHS in
 * assets/controllers/date_picker_controller.js.
 */
final class DateOnlyExtension extends AbstractExtension
{
    /** @var list<string> */
    private const array MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('date_only', $this->formatDateOnly(...)),
            new TwigFilter('date_only_short', $this->formatDateOnlyShort(...)),
        ];
    }

    public function formatDateOnly(?\DateTimeInterface $date, string $format = 'd/m/Y'): string
    {
        return $date?->format($format) ?? '';
    }

    public function formatDateOnlyShort(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return sprintf(
            '%s/%s/%s',
            $date->format('d'),
            self::MONTHS[(int) $date->format('n') - 1],
            $date->format('Y'),
        );
    }
}
