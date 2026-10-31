<?php
// tests/Twig/DateOnlyExtensionTest.php

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * `date_only` exists so a DATE column can never be re-interpreted as an
 * instant. The regression it pins: the transactions table rendered every
 * movement/liquidation one day early because it formatted the hydrated UTC
 * midnight in São Paulo time.
 */
final class DateOnlyExtensionTest extends KernelTestCase
{
    public function testTheFilterIsRegisteredWithTwig(): void
    {
        $twig = $this->twig();

        self::assertNotNull($twig->getFilter('date_only'));
        self::assertNotNull($twig->getFilter('date_only_short'));
    }

    public function testItKeepsTheCalendarDayOfAUtcMidnightDate(): void
    {
        $hydrated = new \DateTime('2026-10-22 00:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            '22/10/2026',
            $this->render('{{ d|date_only }}', $hydrated)
        );
    }

    /**
     * The exact shape of the bug, for contrast: the same value through the
     * plain date filter with a display timezone lands on the previous day.
     */
    public function testThePlainDateFilterWithADisplayTimezoneIsTheBugBeingAvoided(): void
    {
        $hydrated = new \DateTime('2026-10-22 00:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            '21/10/2026',
            $this->render("{{ d|date('d/m/Y', 'America/Sao_Paulo') }}", $hydrated)
        );
    }

    public function testItFormatsInTheValuesOwnTimezone(): void
    {
        // Midnight in São Paulo is still the 22nd there, so it must not be
        // pushed to the 21st (or the 23rd) by a fixed display timezone.
        $saoPaulo = new \DateTime('2026-10-22 00:00:00', new \DateTimeZone('America/Sao_Paulo'));

        self::assertSame('22/10/2026', $this->render('{{ d|date_only }}', $saoPaulo));
        self::assertSame('22/10/2026 00:00', $this->render("{{ d|date_only('d/m/Y H:i') }}", $saoPaulo));
    }

    /**
     * dd/mmm/yyyy listings: 07/09/2023 reads 07/set/2023. Lowercase, no dot —
     * ICU's pt_BR abbreviation is "set." and would not round-trip through the
     * picker, so the names are pinned here.
     */
    public function testTheShortFilterSpellsTheMonthInPortuguese(): void
    {
        $expected = [
            '2023-09-07' => '07/set/2023',
            '2026-01-01' => '01/jan/2026',
            '2026-02-28' => '28/fev/2026',
            '2026-03-15' => '15/mar/2026',
            '2026-04-30' => '30/abr/2026',
            '2026-05-09' => '09/mai/2026',
            '2026-06-10' => '10/jun/2026',
            '2026-07-11' => '11/jul/2026',
            '2026-08-12' => '12/ago/2026',
            '2026-10-22' => '22/out/2026',
            '2026-11-13' => '13/nov/2026',
            '2026-12-31' => '31/dez/2026',
        ];

        foreach ($expected as $iso => $rendered) {
            $date = new \DateTime($iso.' 00:00:00', new \DateTimeZone('UTC'));

            self::assertSame($rendered, $this->render('{{ d|date_only_short }}', $date), $iso);
        }
    }

    public function testTheShortFilterKeepsTheCalendarDay(): void
    {
        // Same defect class as date_only: UTC midnight must not slide back a day.
        $hydrated = new \DateTime('2026-10-22 00:00:00', new \DateTimeZone('UTC'));

        self::assertSame('22/out/2026', $this->render('{{ d|date_only_short }}', $hydrated));
    }

    public function testTheShortFilterRendersNothingForAMissingDate(): void
    {
        self::assertSame('', $this->render('{{ d|date_only_short }}', null));
    }

    public function testItRendersNothingForAMissingDate(): void
    {
        self::assertSame('', $this->render('{{ d|date_only }}', null));
    }

    private function twig(): Environment
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof Environment);

        return $twig;
    }

    private function render(string $template, ?\DateTimeInterface $date): string
    {
        return $this->twig()->createTemplate($template)->render(['d' => $date]);
    }
}
