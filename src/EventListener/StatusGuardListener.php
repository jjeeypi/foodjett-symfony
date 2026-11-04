<?php

namespace App\EventListener;

use App\Entity\Order;
use App\Entity\Payment;
use App\Enum\OrderStatus;
use App\Enum\PaymentStatus;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Runtime guard: Order.status may only change through OrderTransitionService and
 * Payment.status only through PaymentTransitionService. Any other write fails the flush.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
final class StatusGuardListener
{
    public function __construct(
        private readonly OrderTransitionService $orderTransitions,
        private readonly PaymentTransitionService $paymentTransitions,
    ) {
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof Order && !$entity instanceof Payment) {
            return;
        }
        if (!$event->hasChangedField('status')) {
            return;
        }

        // The status columns are mapped as strings; the entities expose them as enums.
        $old = $event->getOldValue('status');
        $new = $event->getNewValue('status');
        $newValue = $new instanceof \BackedEnum ? $new->value : (string) $new;
        $authorized = $entity instanceof Order
            ? null !== ($status = OrderStatus::tryFrom($newValue)) && $this->orderTransitions->consumeAuthorizedStatus($entity, $status)
            : null !== ($status = PaymentStatus::tryFrom($newValue)) && $this->paymentTransitions->consumeAuthorizedStatus($entity, $status);

        if (!$authorized) {
            throw new \LogicException(sprintf(
                '%s #%s status cannot change from "%s" to "%s" outside %s.',
                $entity instanceof Order ? 'Order' : 'Payment',
                (string) $entity->getId(),
                $old instanceof \BackedEnum ? $old->value : (string) $old,
                $newValue,
                $entity instanceof Order ? 'OrderTransitionService' : 'PaymentTransitionService',
            ));
        }
    }
}
