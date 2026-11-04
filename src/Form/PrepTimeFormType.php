<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/** Minutes of preparation: the estimate when accepting an order, or extra time later. */
class PrepTimeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('minutes', IntegerType::class, [
            'label' => $options['minutes_label'],
            'constraints' => [new NotNull(), new Range(min: 1, max: $options['max_minutes'])],
            'attr' => ['min' => 1, 'max' => $options['max_minutes']],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'max_minutes' => 180,
            'minutes_label' => 'Preparation time (minutes)',
        ]);
        $resolver->setAllowedTypes('max_minutes', 'int');
        $resolver->setAllowedTypes('minutes_label', 'string');
    }
}
