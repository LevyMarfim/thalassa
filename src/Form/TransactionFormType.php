<?php
// src/Form/TransactionFormType.php

namespace App\Form;

use App\Entity\Account;
use App\Entity\OperationType;
use App\Entity\Ticker;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\MarketEnum;
use App\Enum\OperationEnum;
use App\Repository\AccountRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
        $user = $options['data'] instanceof Transaction ? $options['data']->getUser() : null;

        $builder
            ->add('account', EntityType::class, [
                'class' => Account::class,
                'label' => 'Account',
                'choice_label' => 'name',
                'placeholder' => 'Select…',
                'attr' => ['class' => 'form-input'],
                // Scoped to the owner: an unscoped EntityType would list every
                // user's accounts in this dropdown.
                'query_builder' => static function (AccountRepository $repository) use ($user) {
                    $qb = $repository->createQueryBuilder('a')->orderBy('a.name', 'ASC');
                    if ($user instanceof User) {
                        $qb->andWhere('a.user = :user')->setParameter('user', $user);
                    }

                    return $qb;
                },
                'constraints' => [new NotBlank(message: 'Please choose an account.')],
            ])
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
                // Symbol plus name: a bare symbol is meaningless to anyone who
                // hasn't memorised the tape, and prefixes can collide.
                'choice_label' => static fn (Ticker $ticker): string => sprintf(
                    '%s · %s',
                    $ticker->getTicker(),
                    $ticker->getName() ?? ''
                ),
                'placeholder' => '— None —',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            // Optional cost-basis detail. All four are nullable in the entity
            // and the column, so no NotBlank: an omitted field must map to null
            // rather than fail validation or throw a TypeError.
            ->add('amount', IntegerType::class, [
                'label' => 'Quantity',
                'required' => false,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => '0',
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('unitPrice', NumberType::class, [
                'label' => 'Unit price',
                'required' => false,
                'scale' => 2,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => '0.00',
                    'step' => '0.01',
                    'inputmode' => 'decimal',
                ],
            ])
            ->add('market', EnumType::class, [
                'class' => MarketEnum::class,
                'label' => 'Market',
                'choice_label' => static fn (MarketEnum $case): string => $case->labels(),
                'placeholder' => '— None —',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('invoice', IntegerType::class, [
                'label' => 'Invoice / note',
                'required' => false,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => '0',
                    'inputmode' => 'numeric',
                ],
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
