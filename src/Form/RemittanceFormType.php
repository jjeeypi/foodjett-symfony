<?php

namespace App\Form;

use App\Entity\RiderCashRemittance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/** A request to hand over collected COD cash; an admin confirms it when the money arrives. */
class RemittanceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', TextType::class, [
                'label' => 'Amount (PHP)',
                'empty_data' => '',
                'attr' => ['inputmode' => 'decimal'],
                'constraints' => [
                    new NotBlank(),
                    new Regex(pattern: '/^(?!0+(\.0+)?$)\d{1,8}(\.\d{1,2})?$/', message: 'Enter an amount above zero, like 500 or 120.50.'),
                ],
            ])
            ->add('referenceNote', TextType::class, [
                'label' => 'Reference (optional)',
                'required' => false,
                'help' => 'For example the deposit or transfer reference.',
                'constraints' => [new Length(max: 255)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RiderCashRemittance::class,
        ]);
    }
}
