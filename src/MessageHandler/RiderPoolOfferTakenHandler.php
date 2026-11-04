<?php

namespace App\MessageHandler;

use App\Message\RiderPoolOfferTaken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RiderPoolOfferTakenHandler{
    public function __invoke(RiderPoolOfferTaken $message): void
    {
        // do something with your message
    }
}
