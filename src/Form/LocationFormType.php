<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/** The rider's current position. The styled frontend will fill it from the device's GPS. */
class LocationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('latitude', NumberType::class, [
                'scale' => 7,
                'html5' => true,
                'attr' => ['step' => 'any'],
                'constraints' => [new NotNull(), new Range(min: -90, max: 90)],
            ])
            ->add('longitude', NumberType::class, [
                'scale' => 7,
                'html5' => true,
                'attr' => ['step' => 'any'],
                'constraints' => [new NotNull(), new Range(min: -180, max: 180)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
