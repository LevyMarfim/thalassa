<?php
// src/Controller/Settings/OperationTypeSettingsController.php

namespace App\Controller\Settings;

use App\Entity\OperationType;
use App\Form\OperationTypeFormType;
use App\Repository\OperationTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Operation types are a shared, app-wide vocabulary rather than per-user
 * data, so nothing here is scoped by the logged-in user.
 */
#[Route('/settings/operation-types')]
final class OperationTypeSettingsController extends AbstractController
{
    #[Route('', name: 'app_settings_operation_types', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        OperationTypeRepository $operationTypeRepository,
    ): Response {
        $form = $this->createForm(OperationTypeFormType::class, new OperationType());
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($form->getData());
            $entityManager->flush();
            $this->addFlash('success', 'Operation type created.');

            return $this->redirectToRoute('app_settings_operation_types');
        }

        return $this->render('settings/operation_types.html.twig', [
            'form' => $form,
            'operationTypes' => $operationTypeRepository->findBy([], ['type' => 'ASC']),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_settings_operation_type_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        OperationType $operationType,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(OperationTypeFormType::class, $operationType);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Operation type updated.');

            return $this->redirectToRoute('app_settings_operation_types');
        }

        return $this->render('settings/operation_type_edit.html.twig', [
            'operationType' => $operationType,
            'form' => $form,
        ]);
    }
}
