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
