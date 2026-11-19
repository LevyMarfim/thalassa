<?php
// src/Form/AccountFilterType.php

namespace App\Form;

use App\Dto\AccountFilter;
use App\Enum\CurrencyEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Search/filter/sort bar for the accounts listing. GET keeps a filtered list
 * shareable and needs no CSRF token; every field is optional.
 */
final class AccountFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name contains',
                'required' => false,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'e.g. Nubank',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('currency', EnumType::class, [
                'class' => CurrencyEnum::class,
                'label' => 'Currency',
                'choice_label' => static fn (CurrencyEnum $case): string => $case->labels(),
                'placeholder' => 'All currencies',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Active' => AccountFilter::STATUS_ACTIVE,
                    'Inactive' => AccountFilter::STATUS_INACTIVE,
                ],
                'placeholder' => 'Any status',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('sort', ChoiceType::class, [
                'label' => 'Sort by',
                'choices' => [
                    'Name (A–Z)' => AccountFilter::SORT_NAME,
                    'Balance (high to low)' => AccountFilter::SORT_BALANCE_DESC,
                    'Balance (low to high)' => AccountFilter::SORT_BALANCE_ASC,
                ],
                'attr' => ['class' => 'form-input'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountFilter::class,
            'method' => 'GET',
            'csrf_protection' => false,
            'required' => false,
        ]);
    }
}
