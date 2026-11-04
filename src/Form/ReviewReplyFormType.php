<?php

namespace App\Form;

use App\Entity\RestaurantReview;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** The restaurant's public answer to a review. */
class ReviewReplyFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('restaurantReply', TextareaType::class, [
            'label' => 'Your reply',
            'empty_data' => '',
            'constraints' => [new NotBlank(message: 'Write a reply first.'), new Length(max: 5000)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RestaurantReview::class,
        ]);
    }
}
