<?php

namespace App\Form;

use App\Entity\Admin;
use App\Entity\Order;
use App\Entity\OrderReport;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OrderReportFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('against')
            ->add('type')
            ->add('description')
            ->add('status')
            ->add('resolution')
            ->add('resolvedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('createdAt', null, [
                'widget' => 'single_text'
            ])
            ->add('updatedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('order', EntityType::class, [
                'class' => Order::class,
                'choice_label' => 'id',
            ])
            ->add('reportedBy', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'id',
            ])
            ->add('resolvedByAdmin', EntityType::class, [
                'class' => Admin::class,
                'choice_label' => 'id',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrderReport::class,
        ]);
    }
}
