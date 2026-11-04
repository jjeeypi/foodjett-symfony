<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Ratings for a delivered order. The restaurant and the rider are rated separately, and only
 * the parts that have not been reviewed yet are shown.
 */
class OrderReviewFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $stars = ['5 - Excellent' => 5, '4 - Good' => 4, '3 - Okay' => 3, '2 - Poor' => 2, '1 - Bad' => 1];
        if ($options['rate_restaurant']) {
            $builder->add('restaurantRating', ChoiceType::class, [
                'label' => 'Restaurant',
                'choices' => $stars,
                'required' => false,
                'placeholder' => 'No rating',
            ]);
        }
        if ($options['rate_rider']) {
            $builder->add('riderRating', ChoiceType::class, [
                'label' => 'Rider',
                'choices' => $stars,
                'required' => false,
                'placeholder' => 'No rating',
            ]);
        }
        $builder->add('comment', TextareaType::class, [
            'required' => false,
            'constraints' => [new Length(max: 5000)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'rate_restaurant' => true,
            'rate_rider' => false,
            'constraints' => [new Callback(static function (array $data, ExecutionContextInterface $context): void {
                if (null === ($data['restaurantRating'] ?? null) && null === ($data['riderRating'] ?? null)) {
                    $context->buildViolation('Give at least one rating.')->addViolation();
                }
            })],
        ]);
        $resolver->setAllowedTypes('rate_restaurant', 'bool');
        $resolver->setAllowedTypes('rate_rider', 'bool');
    }
}
