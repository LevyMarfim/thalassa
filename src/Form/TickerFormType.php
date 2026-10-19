<?php
// src/Form/TickerFormType.php

namespace App\Form;

use App\Entity\Ticker;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TickerFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Constraints live on the entity (NotBlank / Length / Regex / UniqueEntity).
        $builder
            ->add('ticker', TextType::class, [
                'label' => 'Symbol',
                'attr' => [
                    'placeholder' => 'e.g. PETR4',
                    'autocomplete' => 'off',
                    'autocapitalize' => 'characters',
                    'spellcheck' => 'false',
                ],
            ])
            // NOT NULL with no default, so the name is required — NotBlank on
            // the entity turns an empty submit into a 422.
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => [
                    'placeholder' => 'e.g. Petróleo Brasileiro',
                    'autocomplete' => 'off',
                    'spellcheck' => 'false',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ticker::class,
        ]);
    }
}
