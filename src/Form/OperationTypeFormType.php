<?php
// src/Form/OperationTypeFormType.php

namespace App\Form;

use App\Entity\OperationType;
use App\Enum\OperationEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class OperationTypeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', TextType::class, [
                'label' => 'Name',
                'attr' => ['placeholder' => 'e.g. PIX'],
                'constraints' => [
                    new NotBlank(message: 'Please enter a name.'),
                    new Length(
                        max: 32,
                        maxMessage: 'The name must be at most {{ limit }} characters long.',
                    ),
                ],
            ])
            ->add('operation', EnumType::class, [
                'class' => OperationEnum::class,
                'label' => 'Operation',
                'choice_label' => static fn (OperationEnum $case): string => $case->labels(),
                'help' => 'Links this type to a credit or debit operation.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OperationType::class,
        ]);
    }
}
