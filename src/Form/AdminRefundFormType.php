<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

/** A full or partial refund an admin records against a paid order. */
class AdminRefundFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', TextType::class, [
                'label' => 'Amount (₱)',
                'required' => false,
                'help' => 'Leave empty to refund everything not yet refunded.',
                'constraints' => [new Regex(pattern: '/^\d{1,8}(\.\d{1,2})?$/', message: 'Enter an amount like 50 or 50.25.')],
            ])
            ->add('reason', TextareaType::class, [
                'required' => false,
                'constraints' => [new Length(max: 1000)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
