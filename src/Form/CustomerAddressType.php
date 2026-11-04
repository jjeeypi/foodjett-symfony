<?php

namespace App\Form;

use App\Entity\CustomerAddress;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A customer's delivery address. The owner and the timestamps are set by the controller. */
class CustomerAddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['help' => 'For example Home or Office.', 'empty_data' => ''])
            ->add('addressLine', TextType::class, ['label' => 'Address', 'empty_data' => '', 'attr' => ['data-location-picker-target' => 'address', 'autocomplete' => 'off', 'placeholder' => 'Start typing your street, barangay or a landmark']])
            ->add('landmark', TextType::class, ['required' => false])
            ->add('deliveryInstructions', TextareaType::class, ['required' => false])
            ->add('latitude', HiddenType::class, ['empty_data' => '', 'attr' => ['data-location-picker-target' => 'latitude']])
            ->add('longitude', HiddenType::class, ['empty_data' => '', 'error_bubbling' => false, 'attr' => ['data-location-picker-target' => 'longitude']])
            ->add('isDefault', CheckboxType::class, ['label' => 'Use as my default address', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CustomerAddress::class,
        ]);
    }
}
