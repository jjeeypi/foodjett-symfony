<?php

namespace App\Form;

use App\Entity\Rider;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RiderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('vehicleType')
            ->add('plateNumber')
            ->add('approvalStatus')
            ->add('rejectionReason')
            ->add('availabilityStatus')
            ->add('currentLatitude')
            ->add('currentLongitude')
            ->add('lastLocationAt', null, [
                'widget' => 'single_text'
            ])
            ->add('cashOnHand')
            ->add('cashRemitLimit')
            ->add('payoutMethod')
            ->add('encryptedPayoutAccountDetails')
            ->add('createdAt', null, [
                'widget' => 'single_text'
            ])
            ->add('updatedAt', null, [
                'widget' => 'single_text'
            ])
            ->add('user', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'id',
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
