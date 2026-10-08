<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Service\RiderPoolEscalationService;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsPeriodicTask(frequency: '1 minute')]
final readonly class RiderPoolEscalationTask
{
    public function __construct(private RiderPoolEscalationService $escalation)
    {
    }

    public function __invoke(): void
    {
        $this->escalation->processPendingOrders();
    }
}
