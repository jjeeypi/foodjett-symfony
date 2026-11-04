<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The account part of every registration. Customers use it alone; the restaurant and
 * rider applications embed it as their "user" field.
 */
class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => $options['name_label'],
                'empty_data' => '',
            ])
            ->add('email', EmailType::class, ['empty_data' => ''])
            ->add('phone', TelType::class, [
                'label' => 'Phone number',
                'empty_data' => '',
                'help' => 'Digits only, with an optional leading +, for example +639171234567.',
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                // read and hashed in the controller, never set on the user directly
                'mapped' => false,
                'first_options' => ['label' => 'Password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirm password', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'The password confirmation does not match.',
                'constraints' => [
                    new NotBlank(message: 'Please enter a password.'),
                    new Length(
                        min: 8,
                        minMessage: 'Your password should be at least {{ limit }} characters.',
                        // max length allowed by Symfony for security reasons
                        max: 4096,
                    ),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'I agree to the terms of service',
                'mapped' => false,
                'constraints' => [
                    new IsTrue(message: 'You should agree to our terms.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'name_label' => 'Full name',
        ]);
        $resolver->setAllowedTypes('name_label', 'string');
    }
}
