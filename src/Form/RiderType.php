<?php

namespace App\Form;

use App\Entity\Rider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/** What an admin may change on a rider: the vehicle and how much COD cash they may hold. */
class RiderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('vehicleType', ChoiceType::class, [
                'label' => 'Vehicle',
                'choices' => array_combine(array_map('ucfirst', Rider::VEHICLE_TYPES), Rider::VEHICLE_TYPES),
            ])
            ->add('plateNumber', TextType::class, ['required' => false])
            ->add('cashRemitLimit', TextType::class, [
                'label' => 'Cash limit (PHP)',
                'empty_data' => '',
                'help' => 'Above this amount the rider must remit before taking another COD order.',
                'constraints' => [new NotBlank(), new Regex(pattern: '/^\d{1,8}(\.\d{1,2})?$/', message: 'Enter an amount of zero or more.')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Rider::class,
        ]);
    }
}
