<?php

namespace App\Form;

use App\Entity\Rider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The rider's vehicle and payout account. Name, phone and photo belong to the user account
 * and the payout account is stored encrypted; the controller copies those.
 */
class RiderProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['mapped' => false, 'constraints' => [new NotBlank(), new Length(max: 255)]])
            ->add('phone', TelType::class, [
                'label' => 'Phone number',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Regex(pattern: '/^\+?[0-9]{7,20}$/', message: 'Enter a valid phone number.')],
            ])
            ->add('vehicleType', ChoiceType::class, [
                'label' => 'Vehicle',
                'choices' => array_combine(array_map('ucfirst', Rider::VEHICLE_TYPES), Rider::VEHICLE_TYPES),
            ])
            ->add('plateNumber', TextType::class, ['required' => false, 'help' => 'Not needed for a bicycle.'])
            ->add('payoutMethod', ChoiceType::class, [
                'label' => 'Payout method',
                'choices' => ['Bank transfer' => Rider::PAYOUT_METHOD_BANK, 'E-wallet' => Rider::PAYOUT_METHOD_EWALLET],
                'required' => false,
                'placeholder' => 'Not set',
            ])
            ->add('payoutAccountName', TextType::class, ['label' => 'Account name', 'mapped' => false, 'required' => false, 'constraints' => [new Length(max: 255)]])
            ->add('payoutAccountNumber', TextType::class, ['label' => 'Account number', 'mapped' => false, 'required' => false, 'constraints' => [new Length(max: 64)]])
            ->add('avatar', FileType::class, [
                'label' => 'Profile photo',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Image(maxSize: '10M', mimeTypes: ProfileFormType::IMAGE_MIME_TYPES)],
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
