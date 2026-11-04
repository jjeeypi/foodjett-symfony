<?php

namespace App\Form;

use App\Entity\Rider;
use App\Entity\RiderDocument;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/** A rider application: the rider's account, vehicle, and required documents. */
class RiderRegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', RegistrationFormType::class, ['label' => 'Your account'])
            ->add('vehicleType', ChoiceType::class, [
                'label' => 'Vehicle',
                'choices' => array_combine(array_map('ucfirst', Rider::VEHICLE_TYPES), Rider::VEHICLE_TYPES),
                'placeholder' => 'Choose a vehicle',
                'empty_data' => '',
            ])
            ->add('plateNumber', TextType::class, [
                'required' => false,
                'help' => 'Not needed for a bicycle.',
            ])
        ;

        foreach (RiderDocument::TYPES as $type) {
            $builder->add('document_'.$type, FileType::class, [
                'label' => ucfirst(str_replace('_', ' ', $type)),
                'mapped' => false,
                'constraints' => [
                    new NotNull(message: 'This document is required.'),
                    new File(maxSize: '10M', mimeTypes: RestaurantRegistrationFormType::DOCUMENT_MIME_TYPES, mimeTypesMessage: 'Upload a PDF or an image.'),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Rider::class,
        ]);
    }
}
