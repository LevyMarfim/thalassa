<?php
// src/Form/TransactionFilterType.php

namespace App\Form;

use App\Dto\TransactionFilter;
use App\Entity\Account;
use App\Entity\OperationType;
use App\Entity\Ticker;
use App\Entity\User;
use App\Enum\OperationEnum;
use App\Repository\AccountRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Filter bar for the transactions listing. Every field is optional, and the
 * form is submitted with GET so a filtered list is a shareable URL rather
 * than a POST body — which also means no CSRF token.
 */
final class TransactionFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $options['user'];

        $builder
            ->add('account', EntityType::class, [
                'class' => Account::class,
                'label' => 'Account',
                'choice_label' => 'name',
                'placeholder' => 'All accounts',
                'required' => false,
                'attr' => ['class' => 'form-input'],
                // Scoped to the owner: an unscoped EntityType would offer every
                // user's accounts as a filter.
                'query_builder' => static function (AccountRepository $repository) use ($user) {
                    return $repository->createQueryBuilder('a')
                        ->andWhere('a.user = :user')
                        ->setParameter('user', $user)
                        ->orderBy('a.name', 'ASC');
                },
            ])
            ->add('operation', EnumType::class, [
                'class' => OperationEnum::class,
                'label' => 'Operation',
                'choice_label' => static fn (OperationEnum $case): string => $case->labels(),
                'placeholder' => 'All operations',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('operationType', EntityType::class, [
                'class' => OperationType::class,
                'label' => 'Operation type',
                'choice_label' => 'type',
                'placeholder' => 'All types',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('ticker', EntityType::class, [
                'class' => Ticker::class,
                'label' => 'Ticker',
                'choice_label' => static fn (Ticker $ticker): string => sprintf(
                    '%s · %s',
                    $ticker->getTicker(),
                    $ticker->getName() ?? ''
                ),
                'placeholder' => 'All tickers',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            // Same text-input/date-picker treatment as the create form: the
            // visible field is dd/mmm/yyyy, the wire value dd/MM/yyyy.
            ->add('movementFrom', DateType::class, [
                'label' => 'Movement from',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd/MM/yyyy',
                'input' => 'datetime_immutable',
                'required' => false,
                'invalid_message' => 'Please enter the date as dd/mm/yyyy (e.g. 25/10/2026).',
                'attr' => [
                    'class' => 'form-input',
                    'data-controller' => 'date-picker',
                    'placeholder' => 'dd/mmm/yyyy',
                    'inputmode' => 'numeric',
                    'maxlength' => '10',
                ],
            ])
            ->add('movementTo', DateType::class, [
                'label' => 'Movement to',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd/MM/yyyy',
                'input' => 'datetime_immutable',
                'required' => false,
                'invalid_message' => 'Please enter the date as dd/mm/yyyy (e.g. 25/10/2026).',
                'attr' => [
                    'class' => 'form-input',
                    'data-controller' => 'date-picker',
                    'placeholder' => 'dd/mmm/yyyy',
                    'inputmode' => 'numeric',
                    'maxlength' => '10',
                ],
            ])
            ->add('description', TextType::class, [
                'label' => 'Description contains',
                'required' => false,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'e.g. PETR4',
                    'autocomplete' => 'off',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TransactionFilter::class,
            'method' => 'GET',
            'csrf_protection' => false,
            // Filtering is a read: an empty field means "no criterion", not a
            // validation error.
            'required' => false,
        ]);

        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
    }
}
