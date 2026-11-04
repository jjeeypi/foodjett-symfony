<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** The days a payout covers: delivered orders from the first to the last day, both included. */
class AdminPayoutPeriodFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $dateOptions = [
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'constraints' => [new NotNull(message: 'Pick a date.'), new LessThanOrEqual(value: 'today', message: 'The period cannot end in the future.')],
        ];
        $builder
            ->add('periodStart', DateType::class, ['label' => 'From'] + $dateOptions)
            ->add('periodEnd', DateType::class, ['label' => 'To'] + $dateOptions)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new Callback(static function (?array $period, ExecutionContextInterface $context): void {
                if (null !== ($period['periodStart'] ?? null) && null !== ($period['periodEnd'] ?? null) && $period['periodEnd'] < $period['periodStart']) {
                    $context->buildViolation('The period must end on or after its first day.')->atPath('[periodEnd]')->addViolation();
                }
            })],
        ]);
    }
}
