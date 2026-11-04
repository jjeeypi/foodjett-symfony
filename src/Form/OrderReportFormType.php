<?php

namespace App\Form;

use App\Entity\OrderReport;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** A problem a customer reports about an order: what went wrong and who it is about. */
class OrderReportFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $label = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Problem',
                'choices' => array_combine(array_map($label, OrderReport::TYPES), OrderReport::TYPES),
                'placeholder' => 'Choose a problem',
                'empty_data' => '',
            ])
            ->add('against', ChoiceType::class, [
                'label' => 'About',
                // customers report restaurants, riders or the platform, never other customers
                'choices' => array_combine(
                    array_map($label, array_values(array_diff(OrderReport::AGAINST_TARGETS, [OrderReport::AGAINST_CUSTOMER]))),
                    array_values(array_diff(OrderReport::AGAINST_TARGETS, [OrderReport::AGAINST_CUSTOMER])),
                ),
                'placeholder' => 'Choose who it is about',
                'empty_data' => '',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'What happened?',
                'empty_data' => '',
                'constraints' => [new NotBlank(message: 'Describe the problem.'), new Length(max: 5000)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrderReport::class,
        ]);
    }
}
