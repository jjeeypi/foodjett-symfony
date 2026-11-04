<?php

namespace App\Form;

use App\Entity\Admin;
use App\Entity\Customer;
use App\Entity\Restaurant;
use App\Entity\Rider;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AdminAccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name')
            ->add('email')
            ->add('phone')
            ->add('password')
            ->add('twoFactorSecret')
            ->add('twoFactorRecoveryCodes')
            ->add('twoFactorConfirmedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('role')
            ->add('status')
            ->add('emailVerifiedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('phoneVerifiedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('avatarPath')
            ->add('lastLoginAt', null, [
                'widget' => 'single_text'
            ])
            ->add('rememberToken')
            ->add('createdAt', null, [
                'widget' => 'single_text'
            ])
            ->add('updatedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('admin', EntityType::class, [
                'class' => Admin::class,
                'choice_label' => 'id',
            ])
            ->add('restaurant', EntityType::class, [
                'class' => Restaurant::class,
                'choice_label' => 'id',
            ])
            ->add('rider', EntityType::class, [
                'class' => Rider::class,
                'choice_label' => 'id',
            ])
            ->add('customer', EntityType::class, [
                'class' => Customer::class,
                'choice_label' => 'id',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
