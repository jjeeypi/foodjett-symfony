<?php

namespace App\Form;

use App\Entity\MenuItemVariant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** An option of a dish (a size, a flavour) that changes its price by a fixed amount. */
class MenuItemVariantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['empty_data' => ''])
            ->add('priceDelta', TextType::class, [
                'label' => 'Price change (PHP)',
                'help' => 'Added to the base price; may be negative.',
                'empty_data' => '0',
                'attr' => ['inputmode' => 'decimal'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MenuItemVariant::class,
        ]);
    }
}
