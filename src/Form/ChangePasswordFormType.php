<?php
// src/Form/ChangePasswordFormType.php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Current password',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new NotBlank(message: 'Please enter your current password.'),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'The new passwords must match.',
                'first_options' => [
                    'label' => 'New password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'second_options' => [
                    'label' => 'Repeat new password',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [
                    new NotBlank(message: 'Please enter a new password.'),
                    new Length(
                        min: 6,
                        minMessage: 'Your password must be at least {{ limit }} characters long.',
                        max: 4096,
                    ),
                ],
            ]);
    }
}
