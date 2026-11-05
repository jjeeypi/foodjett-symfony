<?php

namespace App\Form;

use App\Entity\MenuCategory;
use App\Entity\MenuItem;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

/** A dish on the menu. Only the restaurant's own categories can be chosen. */
class MenuItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('menuCategory', EntityType::class, [
                'label' => 'Category',
                'class' => MenuCategory::class,
                'choices' => $options['categories'],
                'choice_label' => 'name',
            ])
            ->add('name', TextType::class, ['empty_data' => ''])
            ->add('description', TextareaType::class, ['required' => false])
            ->add('basePrice', TextType::class, ['label' => 'Price (₱)', 'empty_data' => '', 'attr' => ['inputmode' => 'decimal']])
            ->add('isAvailable', CheckboxType::class, ['label' => 'Available to order', 'required' => false])
            ->add('isFeatured', CheckboxType::class, ['label' => 'Featured', 'required' => false])
            ->add('availableFrom', TimeType::class, [
                'label' => 'Served from',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Leave both times empty to serve it all day.',
            ])
            ->add('availableUntil', TimeType::class, [
                'label' => 'Served until',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('photo', FileType::class, [
                'mapped' => false,
                'required' => false,
                'constraints' => [new Image(maxSize: '10M', mimeTypes: ProfileFormType::IMAGE_MIME_TYPES)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MenuItem::class,
        ]);
        $resolver->setRequired('categories');
        $resolver->setAllowedTypes('categories', 'array');
    }
}
