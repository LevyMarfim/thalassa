<?php
// src/Form/AccountFormType.php

namespace App\Form;

use App\Entity\Account;
use App\Enum\CurrencyEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Thin form: validation constraints live on the Account entity, matching how
 * TickerFormType and OperationTypeFormType are built.
 */
final class AccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Account name',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'e.g. Nubank, XP Investimentos',
                    'autocomplete' => 'off',
                    'maxlength' => 64,
                ],
            ])
            ->add('currency', EnumType::class, [
                'class' => CurrencyEnum::class,
                'label' => 'Currency',
                'choice_label' => static fn (CurrencyEnum $case): string => $case->labels(),
                'attr' => ['class' => 'form-input'],
            ])
            ->add('active', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Active' => true,
                    'Inactive' => false,
                ],
                // A select, not a checkbox: an unchecked checkbox submits
                // nothing, which the data mapper treats as "leave unchanged"
                // rather than "set false" — a classic way to make a disable
                // control silently stop working.
                'attr' => ['class' => 'form-input'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
        ]);
    }
}
