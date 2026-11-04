<?php

namespace App\Form;

use App\Entity\Rider;
use App\Repository\RiderRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/** Picks the rider an admin hands an order to when the pool found nobody. */
class AdminAssignRiderFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rider', EntityType::class, [
                'class' => Rider::class,
                'query_builder' => static fn (RiderRepository $riders) => $riders->createAssignableQuery(),
                'choice_label' => static fn (Rider $rider): string => sprintf('%s · %s %s · %s', $rider->getUser()->getName(), $rider->getVehicleType(), $rider->getPlateNumber() ?? '', $rider->getAvailabilityStatus()),
                'placeholder' => 'Choose a rider',
                'constraints' => [new NotNull(message: 'Choose a rider.')],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
