<?php

namespace App\MessageHandler;

use App\Message\OrderUpdated;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class OrderUpdatedHandler{
    public function __invoke(OrderUpdated $message): void
    {
        // do something with your message
    }
}
