<?php

namespace App\Form;

use App\Entity\MenuItemAddon;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** An extra a customer can add to a dish for a price. */
class MenuItemAddonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['empty_data' => ''])
            ->add('price', TextType::class, ['label' => 'Price (₱)', 'empty_data' => '', 'attr' => ['inputmode' => 'decimal']])
            ->add('isAvailable', CheckboxType::class, ['label' => 'Available', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MenuItemAddon::class,
        ]);
    }
}
