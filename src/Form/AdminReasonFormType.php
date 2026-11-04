<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** The reason an admin gives when rejecting an application or a document; the applicant sees it. */
class AdminReasonFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextareaType::class, [
            'label' => $options['reason_label'],
            'constraints' => [new NotBlank(message: 'Give a reason.'), new Length(max: 2000)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['reason_label' => 'Reason']);
        $resolver->setAllowedTypes('reason_label', 'string');
    }
}
