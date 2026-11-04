<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Service\OrderTransitionService;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsPeriodicTask(frequency: '1 minute')]
final readonly class RiderPoolEscalationTask
{
    public function __construct(private OrderTransitionService $transitions)
    {
    }

    public function __invoke(): void
    {
        $this->transitions->escalatePendingPoolOrders();
    }
}
