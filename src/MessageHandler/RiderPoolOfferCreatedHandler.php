<?php

namespace App\MessageHandler;

use App\Message\RiderPoolOfferCreated;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RiderPoolOfferCreatedHandler{
    public function __invoke(RiderPoolOfferCreated $message): void
    {
        // do something with your message
    }
}
