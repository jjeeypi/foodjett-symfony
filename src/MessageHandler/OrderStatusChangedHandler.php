<?php

namespace App\MessageHandler;

use App\Message\OrderStatusChanged;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class OrderStatusChangedHandler{
    public function __invoke(OrderStatusChanged $message): void
    {
        // do something with your message
    }
}
