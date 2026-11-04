<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** The code the restaurant reads out when handing over the food. */
class PickupCodeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('pickupCode', TextType::class, [
            'label' => 'Pickup code from the restaurant',
            'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off'],
            'constraints' => [new NotBlank(message: 'Enter the pickup code.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
