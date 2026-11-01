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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class TransactionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $options['data'] instanceof Transaction ? $options['data']->getUser() : null;
        $fillLiquidation = $options['fill_liquidation_from_movement'];

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
            // Text input instead of the native <input type="date">, whose
            // display format is dictated by the browser's locale
            // (mm/dd/yyyy on an en-US browser) and cannot be overridden from
            // the server. The ICU pattern below is the numeric WIRE format —
            // rendering, strict parsing, everything the server sees. The
            // visible dd/jan/yyyy rendering (flatpickr, via the date_picker
            // controller) is presentation layered on top and submits this
            // same numeric value.
            ->add('movement', DateType::class, [
                'label' => 'Movement date',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd/MM/yyyy',
                'invalid_message' => 'Please enter the movement date as dd/mm/yyyy (e.g. 25/10/2026).',
                'attr' => [
                    'class' => 'form-input',
                    // Popup calendar + numbers-only typing (flatpickr). Pure
                    // enhancement: without JS the field stays this text input
                    // and the server-side dd/MM/yyyy parse still applies.
                    'data-controller' => 'date-picker',
                    // The shape of the VISIBLE field: 06/jan/2022.
                    'placeholder' => 'dd/mmm/yyyy',
                    'inputmode' => 'numeric',
                    'maxlength' => '10',
                ],
                'constraints' => [new NotBlank(message: 'Please choose a movement date.')],
            ])
            ->add('liquidation', DateType::class, [
                'label' => $fillLiquidation ? 'Liquidation date (optional)' : 'Liquidation date',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd/MM/yyyy',
                // On the create form the field may be left blank: a PRE_SUBMIT
                // listener below copies the movement date into it, so it is not
                // required in the browser or in validation.
                'required' => !$fillLiquidation,
                'help' => $fillLiquidation ? 'If left empty, the movement date is used.' : null,
                'invalid_message' => 'Please enter the liquidation date as dd/mm/yyyy (e.g. 25/10/2026).',
                'attr' => [
                    'class' => 'form-input',
                    'data-controller' => 'date-picker',
                    'placeholder' => 'dd/mmm/yyyy',
                    'inputmode' => 'numeric',
                    'maxlength' => '10',
                ],
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

        if ($fillLiquidation) {
            $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
                $data = $event->getData();
                if (!\is_array($data)) {
                    return;
                }

                $movement = $data['movement'] ?? null;
                $liquidation = $data['liquidation'] ?? null;

                // Only fill a genuinely blank field. A malformed liquidation
                // keeps its own parse error instead of being overwritten.
                if ((null === $liquidation || '' === $liquidation) && \is_string($movement) && '' !== $movement) {
                    $data['liquidation'] = $movement;
                    $event->setData($data);
                }
            });
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
            // Create form only: a blank liquidation defaults to the movement
            // date. Edit keeps the field required so an update cannot silently
            // move the settlement date.
            'fill_liquidation_from_movement' => false,
        ]);

        $resolver->setAllowedTypes('fill_liquidation_from_movement', 'bool');
    }
}
