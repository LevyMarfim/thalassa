<?php
// src/Controller/Settings/TickerSettingsController.php

namespace App\Controller\Settings;

use App\Entity\Ticker;
use App\Form\TickerFormType;
use App\Repository\TickerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tickers are a shared, app-wide vocabulary rather than per-user data, so
 * nothing here is scoped by the logged-in user.
 */
#[Route('/settings/tickers')]
final class TickerSettingsController extends AbstractController
{
    #[Route('', name: 'app_settings_tickers', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        TickerRepository $tickerRepository,
    ): Response {
        $form = $this->createForm(TickerFormType::class, new Ticker());
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();
            $this->addFlash('success', 'Ticker added.');

            return $this->redirectToRoute('app_settings_tickers');
        }

        return $this->render('settings/tickers.html.twig', [
            'form' => $form,
            'tickers' => $tickerRepository->findBy([], ['ticker' => 'ASC']),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_settings_ticker_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Ticker $ticker,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(TickerFormType::class, $ticker);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Ticker updated.');

            return $this->redirectToRoute('app_settings_tickers');
        }

        return $this->render('settings/ticker_edit.html.twig', [
            'ticker' => $ticker,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_settings_ticker_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Ticker $ticker,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('delete-ticker', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($ticker);
        $entityManager->flush();
        $this->addFlash('success', 'Ticker removed.');

        return $this->redirectToRoute('app_settings_tickers');
    }
}
