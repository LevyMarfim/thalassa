<?php
// src/Controller/SettingsController.php

namespace App\Controller;

use App\Entity\OperationType;
use App\Entity\User;
use App\Form\ChangeEmailFormType;
use App\Form\ChangePasswordFormType;
use App\Form\OperationTypeFormType;
use App\Repository\OperationTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'app_settings')]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        OperationTypeRepository $operationTypeRepository,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $emailForm = $this->createForm(ChangeEmailFormType::class, $user);
        $emailForm->handleRequest($request);
        if ($emailForm->isSubmitted() && $emailForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Your email has been updated.');

            return $this->redirectToRoute('app_settings');
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

                return $this->redirectToRoute('app_settings');
            }
        }

        $operationTypeForm = $this->createForm(OperationTypeFormType::class, new OperationType());
        $operationTypeForm->handleRequest($request);
        if ($operationTypeForm->isSubmitted() && $operationTypeForm->isValid()) {
            $entityManager->persist($operationTypeForm->getData());
            $entityManager->flush();
            $this->addFlash('success', 'Operation type created.');

            return $this->redirectToRoute('app_settings');
        }

        return $this->render('settings/index.html.twig', [
            'emailForm' => $emailForm,
            'passwordForm' => $passwordForm,
            'operationTypeForm' => $operationTypeForm,
            'operationTypes' => $operationTypeRepository->findBy([], ['type' => 'ASC']),
        ]);
    }

    #[Route('/settings/operation-types/{id}/edit', name: 'app_settings_operation_type_edit')]
    public function editOperationType(
        Request $request,
        OperationType $operationType,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(OperationTypeFormType::class, $operationType);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Operation type updated.');

            return $this->redirectToRoute('app_settings');
        }

        return $this->render('settings/operation_type_edit.html.twig', [
            'operationType' => $operationType,
            'form' => $form,
        ]);
    }
}
