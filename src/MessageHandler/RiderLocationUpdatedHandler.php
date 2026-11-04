<?php

namespace App\MessageHandler;

use App\Message\RiderLocationUpdated;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RiderLocationUpdatedHandler{
    public function __invoke(RiderLocationUpdated $message): void
    {
        // do something with your message
    }
}
