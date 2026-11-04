<?php

namespace App\Form;

use App\Entity\Admin;
use App\Entity\Rider;
use App\Entity\RiderCashRemittance;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RemittanceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount')
            ->add('referenceNote')
            ->add('status')
            ->add('remittedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('createdAt', null, [
                'widget' => 'single_text'
            ])
            ->add('updatedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('rider', EntityType::class, [
                'class' => Rider::class,
                'choice_label' => 'id',
            ])
            ->add('confirmedByAdmin', EntityType::class, [
                'class' => Admin::class,
                'choice_label' => 'id',
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
