<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** A new administrator's login. The starting password is hashed by the controller and should be changed after the first sign-in. */
class AdminAccountFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Full name', 'empty_data' => ''])
            ->add('email', EmailType::class, ['empty_data' => ''])
            ->add('phone', TelType::class, ['label' => 'Phone number', 'empty_data' => ''])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'Starting password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirm password', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'The password confirmation does not match.',
                'constraints' => [
                    new NotBlank(message: 'Please enter a password.'),
                    new Length(min: 8, minMessage: 'The password should be at least {{ limit }} characters.', max: 4096),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
