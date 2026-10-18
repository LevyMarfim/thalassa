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
        $builder->add('ticker', TextType::class, [
            'label' => 'Symbol',
            'attr' => [
                'placeholder' => 'e.g. PETR4',
                'autocomplete' => 'off',
                'autocapitalize' => 'characters',
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
