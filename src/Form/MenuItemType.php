<?php

namespace App\Form;

use App\Entity\MenuCategory;
use App\Entity\MenuItem;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MenuItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name')
            ->add('description')
            ->add('photoPath')
            ->add('basePrice')
            ->add('isAvailable')
            ->add('availableFrom', null, [
                'widget' => 'single_text'
            ])
            ->add('availableUntil', null, [
                'widget' => 'single_text'
            ])
            ->add('isFeatured')
            ->add('createdAt', null, [
                'widget' => 'single_text'
            ])
            ->add('updatedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('menuCategory', EntityType::class, [
                'class' => MenuCategory::class,
                'choice_label' => 'id',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MenuItem::class,
        ]);
    }
}
