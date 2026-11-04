<?php

namespace App\Form;

use App\Entity\Restaurant;
use App\Entity\Voucher;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VoucherType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code')
            ->add('scope')
            ->add('type')
            ->add('value')
            ->add('minOrderAmount')
            ->add('usageLimitTotal')
            ->add('usageLimitPerCustomer')
            ->add('startsAt', null, [
                'widget' => 'single_text'
            ])
            ->add('endsAt', null, [
                'widget' => 'single_text'
            ])
            ->add('isActive')
            ->add('createdAt', null, [
                'widget' => 'single_text'
            ])
            ->add('updatedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('restaurant', EntityType::class, [
                'class' => Restaurant::class,
                'choice_label' => 'id',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Voucher::class,
        ]);
    }
}
