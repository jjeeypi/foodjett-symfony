<?php

namespace App\Form;

use App\Entity\DeliveryZone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;

/** A delivery area drawn as a circle: a centre point and a radius in kilometres. */
class DeliveryZoneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['empty_data' => '', 'constraints' => [new NotBlank(message: 'Name the zone.')]])
            ->add('latitude', NumberType::class, [
                'mapped' => false, 'scale' => 7, 'label' => 'Centre latitude',
                'constraints' => [new NotNull(message: 'Enter the centre latitude.'), new Range(min: -90, max: 90)],
            ])
            ->add('longitude', NumberType::class, [
                'mapped' => false, 'scale' => 7, 'label' => 'Centre longitude',
                'constraints' => [new NotNull(message: 'Enter the centre longitude.'), new Range(min: -180, max: 180)],
            ])
            ->add('radiusKm', NumberType::class, [
                'mapped' => false, 'scale' => 2, 'label' => 'Radius (km)',
                'constraints' => [new NotNull(message: 'Enter the radius.'), new Positive(message: 'The radius must be more than zero.')],
            ])
            ->add('isActive', CheckboxType::class, ['label' => 'Active', 'required' => false])
        ;

        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $zone = $event->getData();
            $polygon = $zone instanceof DeliveryZone ? $zone->getPolygon() : [];
            if ([] === $polygon) {
                return;
            }
            $form = $event->getForm();
            $form->get('latitude')->setData($polygon['center'][0] ?? null);
            $form->get('longitude')->setData($polygon['center'][1] ?? null);
            $form->get('radiusKm')->setData($polygon['radius_km'] ?? null);
        });
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            $zone = $form->getData();
            if (!$zone instanceof DeliveryZone || null === $form->get('latitude')->getData() || null === $form->get('longitude')->getData() || null === $form->get('radiusKm')->getData()) {
                return;
            }
            $zone->setPolygon([
                'type' => 'circle',
                'center' => [(float) $form->get('latitude')->getData(), (float) $form->get('longitude')->getData()],
                'radius_km' => (float) $form->get('radiusKm')->getData(),
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeliveryZone::class,
        ]);
    }
}
