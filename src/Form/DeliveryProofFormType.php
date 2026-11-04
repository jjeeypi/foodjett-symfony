<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\NotNull;

/** Proof of delivery: a photo, plus confirming the cash for cash-on-delivery orders. */
class DeliveryProofFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('proof', FileType::class, [
            'label' => 'Photo of the delivered order',
            'constraints' => [
                new NotNull(message: 'A proof-of-delivery photo is required.'),
                new Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp']),
            ],
        ]);
        if ($options['cash_amount']) {
            $builder->add('cashCollected', CheckboxType::class, [
                'label' => sprintf('I collected PHP %s in cash', $options['cash_amount']),
                'constraints' => [new IsTrue(message: 'Confirm that you collected the cash before completing the delivery.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['cash_amount' => null]);
        $resolver->setAllowedTypes('cash_amount', ['null', 'string']);
    }
}
