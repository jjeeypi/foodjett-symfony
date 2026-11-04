<?php

namespace App\Form;

use App\Entity\Restaurant;
use App\Entity\RestaurantDocument;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/** A restaurant application: the owner's account, the restaurant, and its required documents. */
class RestaurantRegistrationFormType extends AbstractType
{
    public const DOCUMENT_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', RegistrationFormType::class, [
                'label' => 'Owner account',
                'name_label' => 'Owner name',
            ])
            ->add('name', TextType::class, ['label' => 'Restaurant name', 'empty_data' => ''])
            ->add('cuisineType', TextType::class, ['label' => 'Cuisine', 'empty_data' => ''])
            ->add('address', TextType::class, ['empty_data' => ''])
            ->add('latitude', TextType::class, ['empty_data' => ''])
            ->add('longitude', TextType::class, ['empty_data' => ''])
        ;

        foreach (RestaurantDocument::TYPES as $type) {
            $builder->add('document_'.$type, FileType::class, [
                'label' => ucfirst(str_replace('_', ' ', $type)),
                'mapped' => false,
                'constraints' => [
                    new NotNull(message: 'This document is required.'),
                    new File(maxSize: '10M', mimeTypes: self::DOCUMENT_MIME_TYPES, mimeTypesMessage: 'Upload a PDF or an image.'),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Restaurant::class,
        ]);
    }
}
