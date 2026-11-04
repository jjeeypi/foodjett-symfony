<?php

namespace App\Form;

use App\Entity\PlatformSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One platform setting. The key is fixed once created, because the code looks settings up by key. */
class PlatformSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('key', TextType::class, [
                'label' => 'Setting',
                'empty_data' => '',
                'disabled' => $options['existing'],
                'help' => 'For example delivery_base_fee or rider_base_pay.',
            ])
            ->add('value', TextType::class, ['empty_data' => ''])
            ->add('description', TextType::class, ['required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlatformSetting::class,
            'existing' => false,
        ]);
        $resolver->setAllowedTypes('existing', 'bool');
    }
}
