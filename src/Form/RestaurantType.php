<?php

namespace App\Form;

use App\Entity\Restaurant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/** What an admin may change on a restaurant, including the commission the platform takes. */
class RestaurantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['empty_data' => ''])
            ->add('description', TextareaType::class, ['required' => false])
            ->add('cuisineType', TextType::class, ['label' => 'Cuisine', 'empty_data' => ''])
            ->add('kind', ChoiceType::class, ['label' => 'Type of place', 'choices' => array_flip(Restaurant::KINDS)])
            ->add('address', TextType::class, ['empty_data' => '', 'attr' => ['data-location-picker-target' => 'address', 'autocomplete' => 'off', 'placeholder' => 'Start typing the street, barangay or a landmark']])
            ->add('latitude', HiddenType::class, ['empty_data' => '', 'attr' => ['data-location-picker-target' => 'latitude']])
            ->add('longitude', HiddenType::class, ['empty_data' => '', 'error_bubbling' => false, 'attr' => ['data-location-picker-target' => 'longitude']])
            ->add('commissionRate', TextType::class, [
                'label' => 'Commission (%)',
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Regex(pattern: '/^(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)$/', message: 'Enter a percentage from 0 to 100.'),
                ],
            ])
            ->add('minOrderAmount', TextType::class, ['label' => 'Minimum order (₱)', 'empty_data' => '0'])
            ->add('defaultPrepTimeMinutes', IntegerType::class, ['label' => 'Usual preparation time (minutes)'])
            ->add('operatingStatus', ChoiceType::class, [
                'label' => 'Status',
                'choices' => array_combine(array_map(static fn (string $s): string => ucfirst(str_replace('_', ' ', $s)), Restaurant::OPERATING_STATUSES), Restaurant::OPERATING_STATUSES),
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
