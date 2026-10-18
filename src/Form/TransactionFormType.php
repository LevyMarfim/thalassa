<?php
// src/Form/TransactionFormType.php

namespace App\Form;

use App\Entity\OperationType;
use App\Entity\Ticker;
use App\Entity\Transaction;
use App\Enum\OperationEnum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class TransactionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('movement', DateType::class, [
                'label' => 'Movement date',
                'widget' => 'single_text',
                'format' => 'yyyy-MM-dd',
                'attr' => ['class' => 'form-input'],
                'constraints' => [new NotBlank(message: 'Please choose a movement date.')],
            ])
            ->add('liquidation', DateType::class, [
                'label' => 'Liquidation date',
                'widget' => 'single_text',
                'format' => 'yyyy-MM-dd',
                'attr' => ['class' => 'form-input'],
                'constraints' => [new NotBlank(message: 'Please choose a liquidation date.')],
            ])
            ->add('operation', EnumType::class, [
                'class' => OperationEnum::class,
                'label' => 'Operation',
                'choice_label' => static fn (OperationEnum $case): string => $case->labels(),
                'attr' => ['class' => 'form-input'],
                'constraints' => [new NotBlank(message: 'Please choose an operation.')],
            ])
            ->add('operationType', EntityType::class, [
                'class' => OperationType::class,
                'label' => 'Operation type',
                'choice_label' => 'type',
                'placeholder' => 'Select…',
                'attr' => ['class' => 'form-input'],
                'constraints' => [new NotBlank(message: 'Please choose an operation type.')],
            ])
            ->add('ticker', EntityType::class, [
                'class' => Ticker::class,
                'label' => 'Ticker',
                'choice_label' => 'ticker',
                'placeholder' => '— None —',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('description', TextType::class, [
                'label' => 'Description',
                'attr' => ['placeholder' => 'e.g. Compra de PETR4', 'autocomplete' => 'off'],
                'constraints' => [
                    new NotBlank(message: 'Please enter a description.'),
                    new Length(
                        max: 255,
                        maxMessage: 'The description must be at most {{ limit }} characters long.',
                    ),
                ],
            ])
            ->add('totalPrice', NumberType::class, [
                'label' => 'Total price',
                'scale' => 2,
                // Money is entered as a plain decimal; kept as a float because that
                // is how the entity/column are defined today (see note in summary).
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => '0.00',
                    'step' => '0.01',
                    'inputmode' => 'decimal',
                ],
                'constraints' => [new NotBlank(message: 'Please enter a total price.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
        ]);
    }
}
