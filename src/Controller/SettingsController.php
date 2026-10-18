<?php
// src/Controller/SettingsController.php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangeEmailFormType;
use App\Form\ChangePasswordFormType;
use App\Repository\OperationTypeRepository;
use App\Repository\TickerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Settings hub and account section. The other contexts (operation types,
 * tickers) live in src/Controller/Settings/ so each page stays small.
 */
final class SettingsController extends AbstractController
{
    /**
     * Landing page: links into each settings context. Deliberately handles no
     * forms — a section's write belongs on that section's own page.
     */
    #[Route('/settings', name: 'app_settings')]
    public function index(
        OperationTypeRepository $operationTypeRepository,
        TickerRepository $tickerRepository,
    ): Response {
        return $this->render('settings/index.html.twig', [
            'operationTypeCount' => $operationTypeRepository->count([]),
            'tickerCount' => $tickerRepository->count([]),
        ]);
    }

    #[Route('/settings/account', name: 'app_settings_account')]
    public function account(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $emailForm = $this->createForm(ChangeEmailFormType::class, $user);
        $emailForm->handleRequest($request);
        if ($emailForm->isSubmitted() && $emailForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Your email has been updated.');

            return $this->redirectToRoute('app_settings_account');
        }

        $passwordForm = $this->createForm(ChangePasswordFormType::class);
        $passwordForm->handleRequest($request);
        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            if (!$passwordHasher->isPasswordValid($user, $passwordForm->get('currentPassword')->getData())) {
                $passwordForm->get('currentPassword')->addError(new FormError('The current password is incorrect.'));
            } else {
                $user->setPassword($passwordHasher->hashPassword($user, $passwordForm->get('newPassword')->getData()));
                $entityManager->flush();
                $this->addFlash('success', 'Your password has been updated.');

                return $this->redirectToRoute('app_settings_account');
            }
        }

        return $this->render('settings/account.html.twig', [
            'emailForm' => $emailForm,
            'passwordForm' => $passwordForm,
        ]);
    }
}
