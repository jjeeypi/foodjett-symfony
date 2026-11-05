<?php

namespace App\Form;

use App\Entity\Voucher;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A discount code. Scope, owner and timestamps are set by the controller (restaurant or admin). */
class VoucherType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, ['help' => 'Customers type this at checkout; stored in capitals.', 'empty_data' => ''])
            ->add('type', ChoiceType::class, [
                'label' => 'Discount',
                'choices' => ['Percentage off' => Voucher::TYPE_PERCENTAGE, 'Fixed amount off' => Voucher::TYPE_FIXED, 'Free delivery' => Voucher::TYPE_FREE_DELIVERY],
            ])
            ->add('value', TextType::class, [
                'label' => 'Value',
                'required' => false,
                'help' => 'Percent or PHP amount; leave empty for free delivery.',
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('minOrderAmount', TextType::class, ['label' => 'Minimum order (₱)', 'empty_data' => '0', 'attr' => ['inputmode' => 'decimal']])
            ->add('usageLimitTotal', IntegerType::class, ['label' => 'Total uses', 'required' => false, 'help' => 'Leave empty for no limit.'])
            ->add('usageLimitPerCustomer', IntegerType::class, ['label' => 'Uses per customer', 'empty_data' => '1'])
            ->add('startsAt', DateTimeType::class, ['label' => 'Starts', 'input' => 'datetime_immutable', 'widget' => 'single_text', 'required' => false])
            ->add('endsAt', DateTimeType::class, ['label' => 'Ends', 'input' => 'datetime_immutable', 'widget' => 'single_text', 'required' => false])
            ->add('isActive', CheckboxType::class, ['label' => 'Active', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Voucher::class,
        ]);
    }
}
