<?php

namespace App\Form;

use App\Entity\CustomerAddress;
use App\Entity\Payment;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Delivery and payment choices for the cart. OrderTransitionService validates the amounts,
 * the voucher and the cart itself, so this form only collects the values.
 */
class CheckoutFormType extends AbstractType
{
    private const METHOD_LABELS = [
        Payment::METHOD_COD => 'Cash on delivery',
        Payment::METHOD_GCASH => 'GCash',
        Payment::METHOD_CARD => 'Card',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('address', EntityType::class, [
                'label' => 'Deliver to',
                'class' => CustomerAddress::class,
                'choices' => $options['addresses'],
                'choice_label' => static fn (CustomerAddress $address): string => $address->getLabel().': '.$address->getAddressLine(),
                'expanded' => true,
                'constraints' => [new NotNull(message: 'Choose a delivery address.')],
            ])
            ->add('paymentMethod', ChoiceType::class, [
                'label' => 'Payment',
                'choices' => array_flip(array_intersect_key(self::METHOD_LABELS, array_flip(Payment::METHODS))),
                'expanded' => true,
            ])
            ->add('tipAmount', TextType::class, [
                'label' => 'Rider tip (₱)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('voucherCode', TextType::class, ['label' => 'Voucher code', 'required' => false])
            ->add('notes', TextareaType::class, ['label' => 'Notes for the restaurant', 'required' => false])
            // One token per checkout page: submitting the same page twice places one order.
            ->add('checkoutToken', HiddenType::class)
            ->add('preview', SubmitType::class, ['label' => 'Update total'])
            ->add('place', SubmitType::class, ['label' => 'Place order'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('addresses');
        $resolver->setAllowedTypes('addresses', 'array');
    }
}
