<?php

namespace App\Form;

use App\Entity\OrderReport;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** A problem the rider reports during a delivery; who it is about follows from its type. */
class RiderIssueFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Problem',
                'choices' => array_combine(array_map(static fn (string $type): string => ucfirst(str_replace('_', ' ', $type)), OrderReport::TYPES), OrderReport::TYPES),
                'placeholder' => 'Choose a problem',
                'constraints' => [new NotBlank(), new Choice(choices: OrderReport::TYPES)],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'What happened?',
                'constraints' => [new NotBlank(message: 'Describe the problem.'), new Length(max: 5000)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
