<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Opening and closing time for each day of the week (0 = Sunday). A day left empty is closed;
 * a closing time before the opening time means the restaurant stays open past midnight.
 */
class OperatingHoursFormType extends AbstractType
{
    public const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (self::DAYS as $day => $name) {
            foreach (['opens' => 'opens', 'closes' => 'closes'] as $key => $label) {
                $builder->add(sprintf('%s_%d', $key, $day), TimeType::class, [
                    'label' => sprintf('%s %s', $name, $label),
                    'input' => 'datetime_immutable',
                    'widget' => 'single_text',
                    'required' => false,
                ]);
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new Callback(static function (?array $data, ExecutionContextInterface $context): void {
                foreach (array_keys(self::DAYS) as $day) {
                    $opens = $data['opens_'.$day] ?? null;
                    $closes = $data['closes_'.$day] ?? null;
                    if ((null === $opens) !== (null === $closes)) {
                        $context->buildViolation(sprintf('Give both times for %s, or neither if you are closed.', self::DAYS[$day]))
                            ->atPath('[opens_'.$day.']')->addViolation();
                    } elseif (null !== $opens && $opens->format('H:i') === $closes->format('H:i')) {
                        $context->buildViolation(sprintf('%s opens and closes at the same time.', self::DAYS[$day]))
                            ->atPath('[opens_'.$day.']')->addViolation();
                    }
                }
            })],
        ]);
    }
}
