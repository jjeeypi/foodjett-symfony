<?php

namespace App\Form;

use App\Entity\Restaurant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The restaurant's public details, delivery settings and payout account. The phone belongs to
 * the owner's account and the payout account fields fill payoutAccountDetails; the controller
 * copies both.
 */
class RestaurantProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Restaurant name', 'empty_data' => ''])
            ->add('description', TextareaType::class, ['required' => false])
            ->add('cuisineType', TextType::class, ['label' => 'Cuisine', 'empty_data' => ''])
            ->add('kind', ChoiceType::class, ['label' => 'Type of place', 'choices' => array_flip(Restaurant::KINDS)])
            ->add('phone', TelType::class, [
                'label' => 'Phone number',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Regex(pattern: '/^\+?[0-9]{7,20}$/', message: 'Enter a valid phone number.')],
            ])
            ->add('address', TextType::class, ['empty_data' => '', 'attr' => ['data-location-picker-target' => 'address', 'autocomplete' => 'off', 'placeholder' => 'Start typing the street, barangay or a landmark']])
            ->add('latitude', HiddenType::class, ['empty_data' => '', 'attr' => ['data-location-picker-target' => 'latitude']])
            ->add('longitude', HiddenType::class, ['empty_data' => '', 'error_bubbling' => false, 'attr' => ['data-location-picker-target' => 'longitude']])
            ->add('defaultPrepTimeMinutes', IntegerType::class, ['label' => 'Usual preparation time (minutes)'])
            ->add('minOrderAmount', TextType::class, ['label' => 'Minimum order (₱)', 'empty_data' => '0', 'attr' => ['inputmode' => 'decimal']])
            ->add('payoutMethod', ChoiceType::class, [
                'label' => 'Payout method',
                'choices' => ['Bank transfer' => Restaurant::PAYOUT_METHOD_BANK, 'E-wallet' => Restaurant::PAYOUT_METHOD_EWALLET],
                'required' => false,
                'placeholder' => 'Not set',
            ])
            ->add('payoutAccountName', TextType::class, ['label' => 'Account name', 'mapped' => false, 'required' => false, 'constraints' => [new Length(max: 255)]])
            ->add('payoutAccountNumber', TextType::class, ['label' => 'Account number', 'mapped' => false, 'required' => false, 'constraints' => [new Length(max: 64)]])
            ->add('logo', FileType::class, [
                'mapped' => false,
                'required' => false,
                'constraints' => [new Image(maxSize: '10M', mimeTypes: ProfileFormType::IMAGE_MIME_TYPES)],
            ])
            ->add('coverPhoto', FileType::class, [
                'label' => 'Cover photo',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Image(maxSize: '10M', mimeTypes: ProfileFormType::IMAGE_MIME_TYPES)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Restaurant::class,
        ]);
    }
}
