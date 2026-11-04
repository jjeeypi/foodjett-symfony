<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/** The rider's current position, filled from the device's location (sent automatically) or a pin on the map. */
class LocationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('latitude', HiddenType::class, [
                'attr' => ['data-location-picker-target' => 'latitude'],
                'constraints' => [new NotBlank(), new Range(min: -90, max: 90)],
            ])
            ->add('longitude', HiddenType::class, [
                'attr' => ['data-location-picker-target' => 'longitude'],
                'constraints' => [new NotBlank(), new Range(min: -180, max: 180)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
