<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/** Uploads one required document; the choices are the types that are missing or were rejected. */
class DocumentUploadFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Document',
                'choices' => array_combine(
                    array_map(static fn (string $type): string => ucfirst(str_replace('_', ' ', $type)), $options['types']),
                    $options['types'],
                ),
            ])
            ->add('file', FileType::class, [
                'constraints' => [
                    new NotNull(message: 'Choose a file to upload.'),
                    new File(maxSize: '10M', mimeTypes: RestaurantRegistrationFormType::DOCUMENT_MIME_TYPES, mimeTypesMessage: 'Upload a PDF or an image.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('types');
        $resolver->setAllowedTypes('types', 'array');
    }
}
