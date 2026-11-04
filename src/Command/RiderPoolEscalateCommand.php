<?php

namespace App\Command;

use App\Service\OrderTransitionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Advances every order still waiting for a rider through the escalation stages
 * (wider radius, incentive, admin alert, customer notice, auto-cancel).
 * The scheduler runs it every minute; it can also be run by hand.
 */
#[AsCommand(
    name: 'app:rider-pool:escalate',
    description: 'Escalate orders that are still waiting for a rider',
)]
#[AsPeriodicTask(frequency: '1 minute')]
class RiderPoolEscalateCommand
{
    public function __construct(private readonly OrderTransitionService $transitions)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $changed = $this->transitions->escalatePendingPoolOrders();

        $io->success(sprintf('%d order(s) escalated.', $changed));

        return Command::SUCCESS;
    }
}
